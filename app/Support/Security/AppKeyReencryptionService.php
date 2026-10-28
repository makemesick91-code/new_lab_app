<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — moves encrypted columns from a previous
 * application key to the current one.
 *
 * Every value is classified against the CURRENT key alone and then against the
 * current plus every configured previous key:
 *
 *   current        decrypts with the current key; nothing to do
 *   previous       decrypts only with a previous key; must be rewritten before
 *                  that key is retired
 *   undecryptable  decrypts with no configured key; the run refuses to write
 *                  anything while such a row exists
 *
 * A rewrite happens one row per transaction, re-reads the value under a row lock,
 * encrypts the identical plaintext with the current key, proves the new value
 * decrypts with the current key alone, and writes through the query builder so
 * business timestamps and model observers are untouched. Plaintext and keys are
 * never returned, logged or printed.
 */
final class AppKeyReencryptionService
{
    public const STATE_CURRENT = 'current';

    public const STATE_PREVIOUS = 'previous';

    public const STATE_UNDECRYPTABLE = 'undecryptable';

    public function __construct(private readonly Encrypter $encrypter) {}

    /** Number of previous keys the running configuration carries. */
    public function previousKeyCount(): int
    {
        return count($this->encrypter->getPreviousKeys());
    }

    /**
     * Read-only classification of every configured column.
     *
     * @return array{columns: list<array{table: string, column: string, total: int, current: int, previous: int, undecryptable: int}>, totals: array{total: int, current: int, previous: int, undecryptable: int}}
     */
    public function inspect(): array
    {
        $columns = [];
        $totals = ['total' => 0, 'current' => 0, 'previous' => 0, 'undecryptable' => 0];

        foreach ($this->columns() as $spec) {
            $row = ['table' => $spec['table'], 'column' => $spec['column'], 'total' => 0, 'current' => 0, 'previous' => 0, 'undecryptable' => 0];

            DB::table($spec['table'])
                ->whereNotNull($spec['column'])
                ->select([$spec['key'], $spec['column']])
                ->orderBy($spec['key'])
                ->chunk($this->chunkSize(), function ($records) use (&$row, $spec): void {
                    foreach ($records as $record) {
                        $row['total']++;
                        $row[$this->classify((string) $record->{$spec['column']})]++;
                    }
                });

            foreach (['total', 'current', 'previous', 'undecryptable'] as $k) {
                $totals[$k] += $row[$k];
            }
            $columns[] = $row;
        }

        return ['columns' => $columns, 'totals' => $totals];
    }

    /**
     * Rewrite every value still encrypted under a previous key.
     *
     * @return array{rewritten: int, columns: list<array{table: string, column: string, rewritten: int}>}
     *
     * @throws RuntimeException when an undecryptable value exists (nothing is written) or a rewrite fails verification
     */
    public function reencrypt(): array
    {
        $before = $this->inspect();
        if ($before['totals']['undecryptable'] > 0) {
            throw new RuntimeException('Refusing to re-encrypt: '.$before['totals']['undecryptable'].' value(s) decrypt with no configured key. Nothing was written.');
        }

        $result = ['rewritten' => 0, 'columns' => []];

        foreach ($this->columns() as $spec) {
            $ids = [];
            DB::table($spec['table'])
                ->whereNotNull($spec['column'])
                ->select([$spec['key'], $spec['column']])
                ->orderBy($spec['key'])
                ->chunk($this->chunkSize(), function ($records) use (&$ids, $spec): void {
                    foreach ($records as $record) {
                        if ($this->classify((string) $record->{$spec['column']}) === self::STATE_PREVIOUS) {
                            $ids[] = $record->{$spec['key']};
                        }
                    }
                });

            $rewritten = 0;
            foreach ($ids as $id) {
                if ($this->rewriteRow($spec, $id)) {
                    $rewritten++;
                }
            }

            $result['rewritten'] += $rewritten;
            $result['columns'][] = ['table' => $spec['table'], 'column' => $spec['column'], 'rewritten' => $rewritten];
        }

        return $result;
    }

    /** @param array{table: string, key: string, column: string} $spec */
    private function rewriteRow(array $spec, mixed $id): bool
    {
        return DB::transaction(function () use ($spec, $id): bool {
            $raw = DB::table($spec['table'])->where($spec['key'], $id)->lockForUpdate()->value($spec['column']);

            if ($raw === null || $this->classify((string) $raw) !== self::STATE_PREVIOUS) {
                return false;
            }

            $plain = $this->encrypter->decryptString((string) $raw);
            $current = $this->currentOnly();
            $rewritten = $current->encryptString($plain);

            if (! hash_equals($plain, $current->decryptString($rewritten))) {
                throw new RuntimeException("Verification failed for {$spec['table']}.{$spec['column']}; the transaction was rolled back.");
            }

            DB::table($spec['table'])->where($spec['key'], $id)->update([$spec['column'] => $rewritten]);

            return true;
        });
    }

    private function classify(string $payload): string
    {
        try {
            $this->currentOnly()->decryptString($payload);

            return self::STATE_CURRENT;
        } catch (DecryptException) {
            // fall through to the previous keys
        }

        try {
            $this->encrypter->decryptString($payload);

            return self::STATE_PREVIOUS;
        } catch (DecryptException) {
            return self::STATE_UNDECRYPTABLE;
        }
    }

    private function currentOnly(): Encrypter
    {
        return new Encrypter($this->encrypter->getKey(), (string) config('app.cipher', 'AES-256-CBC'));
    }

    /** @return list<array{table: string, key: string, column: string}> */
    private function columns(): array
    {
        $columns = [];
        foreach ((array) config('app_key_rotation.encrypted_columns', []) as $spec) {
            $columns[] = ['table' => (string) $spec['table'], 'key' => (string) ($spec['key'] ?? 'id'), 'column' => (string) $spec['column']];
        }

        return $columns;
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('app_key_rotation.chunk_size', 200));
    }
}
