<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

use App\Modules\LegacyRme\Support\LegacyRmeLifecycleDenied;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns a canonical refusal into a STABLE CODE plus a SAFE MESSAGE — PR1 §3, §7.
 *
 * THIS CLASS DECIDES NOTHING. It classifies an exception the canonical review
 * path already raised, so that a batch report can aggregate refusals by reason
 * and an operator can see which rows need a different reviewer. If this
 * classifier were deleted the gate behaviour would be identical; only the
 * report would get less readable.
 *
 * WHY THE MESSAGE IS CARRIED VERBATIM
 * -----------------------------------
 * The canonical layer already produces operator-facing Indonesian messages that
 * are precise about *why* ("Akun yang mengunggah dokumen ini tidak boleh
 * meninjaunya sendiri…"). Paraphrasing them here would create a second, drifting
 * source of truth. So the canonical message is the authority shown to the
 * operator, and the code exists only for grouping.
 *
 * WHY UNKNOWN IS A REAL OUTCOME
 * -----------------------------
 * Where a refusal cannot be identified confidently the result is
 * REFUSAL_UNKNOWN with the canonical message attached — never a guess, and
 * never silently folded into success. A validation failure keyed on `status`
 * is genuinely ambiguous in the canonical code (both a stale transition and
 * unusable rendered pages refuse with that field), so this classifier does not
 * pretend to tell them apart from the exception alone.
 */
final class LegacyBatchReviewRefusalClassifier
{
    private function __construct() {}

    /**
     * Map the lifecycle refusal codes RME's own service already assigns.
     *
     * These are reliable because the canonical layer hands us the code directly
     * on the exception rather than making us infer it.
     */
    private const LIFECYCLE_CODE_MAP = [
        LegacyRmeLifecycleRefusal::FEATURE_DISABLED => LegacyBatchReviewReason::REFUSAL_FEATURE_DISABLED,
        LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE => LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE,
        LegacyRmeLifecycleRefusal::IMPORT_REQUIRED => LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE,
        LegacyRmeLifecycleRefusal::PERMISSION_DENIED => LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED,
        LegacyRmeLifecycleRefusal::POLICY_DENIED => LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED,
        LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES => LegacyBatchReviewReason::REFUSAL_SEPARATION_OF_DUTIES,
        LegacyRmeLifecycleRefusal::TRANSITION_NOT_ALLOWED => LegacyBatchReviewReason::REFUSAL_STALE_STATUS,
    ];

    /**
     * Validation field keys the canonical layer uses, where the key alone is
     * unambiguous.
     *
     * `actor` is the separation-of-duties field and is used for nothing else in
     * either module, which is why SOD classification is exact rather than
     * best-effort. `status` is deliberately NOT in this map.
     */
    private const FIELD_MAP = [
        'actor' => LegacyBatchReviewReason::REFUSAL_SEPARATION_OF_DUTIES,
        'origin_branch_id' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'patient_id' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'selected_rme_date' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'selected_odontogram_date' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'source_rm' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'source_rm_raw' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
        'verification_visit_id' => LegacyBatchReviewReason::REFUSAL_BINDING_INVALID,
    ];

    public static function classify(Throwable $exception): LegacyBatchReviewApplyOutcome
    {
        // 1. RME hands us its own refusal code. Most precise path available.
        if ($exception instanceof LegacyRmeLifecycleDenied) {
            $code = self::LIFECYCLE_CODE_MAP[$exception->refusalCode]
                ?? LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED;

            return LegacyBatchReviewApplyOutcome::refused($code, self::safeMessage($exception->getMessage()));
        }

        // 2. A field-keyed validation failure from either module's publish
        //    service or from RME's lifecycle separation gate.
        if ($exception instanceof ValidationException) {
            return self::classifyValidation($exception);
        }

        // 3. The feature guard aborts 404 when migration capability is off, and
        //    an out-of-scope import also resolves to 404 by design (so an actor
        //    cannot probe which ids exist in a branch they cannot see).
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            if ($status === 404) {
                return LegacyBatchReviewApplyOutcome::refused(
                    LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE,
                    'Dokumen tidak tersedia atau kapabilitas migrasi tidak aktif.'
                );
            }

            if ($status === 403) {
                return LegacyBatchReviewApplyOutcome::refused(
                    LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED,
                    'Tidak berwenang meninjau dokumen ini.'
                );
            }
        }

        // 4. A plain policy/gate denial.
        if ($exception instanceof AuthorizationException) {
            return LegacyBatchReviewApplyOutcome::refused(
                LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED,
                self::safeMessage($exception->getMessage()) ?? 'Tidak berwenang meninjau dokumen ini.'
            );
        }

        // 5. Anything else. Classified as unknown WITH the canonical message —
        //    never dropped, never treated as applied.
        return LegacyBatchReviewApplyOutcome::refused(
            LegacyBatchReviewReason::REFUSAL_UNKNOWN,
            self::safeMessage($exception->getMessage())
        );
    }

    private static function classifyValidation(ValidationException $exception): LegacyBatchReviewApplyOutcome
    {
        $errors = $exception->errors();
        $message = null;

        foreach ($errors as $messages) {
            if (is_array($messages) && $messages !== []) {
                $message = self::safeMessage((string) reset($messages));

                break;
            }
        }

        foreach (array_keys($errors) as $field) {
            $code = self::FIELD_MAP[(string) $field] ?? null;

            if ($code !== null) {
                return LegacyBatchReviewApplyOutcome::refused($code, $message);
            }
        }

        // `status` and anything unmapped. Honest UNKNOWN with the canonical
        // message, rather than inventing a distinction the exception cannot
        // support.
        return LegacyBatchReviewApplyOutcome::refused(
            LegacyBatchReviewReason::REFUSAL_UNKNOWN,
            $message
        );
    }

    /**
     * Bound the stored message and keep anything path-like or structural out of
     * it. Canonical messages are already operator-safe prose; this is a belt-and
     * -braces guard so an unexpected exception type cannot write a stack frame,
     * a filesystem path or a SQL fragment into an operator-visible column.
     */
    private static function safeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $trimmed = trim($message);

        if ($trimmed === '') {
            return null;
        }

        // NOTE: every alternative here must be non-empty. An empty trailing
        // alternative would match any string and silently replace every
        // canonical message with the generic fallback below.
        $structural = '#(?:/[A-Za-z0-9_.\-]+){2,}'      // a filesystem-ish path
            .'|\bselect\b.+?\bfrom\b'                    // a SQL fragment
            .'|SQLSTATE'                                 // a PDO error
            .'|\bexception\b'                            // an exception class name
            .'|::'                                       // a PHP symbol reference
            .'|\$[A-Za-z_]'                              // a PHP variable
            .'#i';

        if (preg_match($structural, $trimmed) === 1) {
            return 'Ditolak oleh sistem. Hubungi administrator jika berulang.';
        }

        return mb_substr($trimmed, 0, 500);
    }
}
