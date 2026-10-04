<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

use App\Modules\LegacyImport\Exceptions\LegacyDocumentSlotOccupied;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleDenied;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns a canonical PUBLISH refusal into a stable §11 code — PR2 §4, §11.
 *
 * THIS CLASS DECIDES NOTHING. It classifies an exception the canonical publish
 * already raised, so a batch report can group refusals and an operator can see
 * which rows need a different publisher, a cleared block, or a fresh import. If
 * it were deleted, no document would publish that does not publish today.
 *
 * WHERE THE PRECISE CODES ACTUALLY COME FROM — stated honestly
 * ------------------------------------------------------------
 * A canonical date refusal arrives as a ValidationException keyed on the date
 * field. The exception does NOT carry the rule code, so from the exception
 * alone this class cannot tell a native-boundary failure from a
 * future-date or birth-date failure, and it does not pretend to: it reports the
 * coarse DATE_RULE_FAILED with the canonical message attached.
 *
 * The precise NATIVE_BOUNDARY_FAILED code is produced by the ELIGIBILITY
 * service, which evaluates the date rules directly and reads `$result->code`.
 * Because that revalidation runs immediately before the canonical call (§6), a
 * native-boundary failure is normally caught there with the exact code and the
 * canonical publish is never attempted. Only a failure that appears in the gap
 * between those two steps — a genuine race — degrades to DATE_RULE_FAILED.
 *
 * `status`-keyed refusals are likewise ambiguous in the canonical code (both a
 * stale transition and unusable/missing source bytes refuse on that field), so
 * they are reported as STALE_ITEM with the canonical message rather than
 * guessed at.
 */
final class LegacyBatchPublishRefusalClassifier
{
    private function __construct() {}

    /** The lifecycle refusal codes RME's own service hands us on the exception. */
    private const LIFECYCLE_CODE_MAP = [
        LegacyRmeLifecycleRefusal::FEATURE_DISABLED => LegacyBatchPublishReason::FEATURE_DISABLED,
        LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE => LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
        LegacyRmeLifecycleRefusal::IMPORT_REQUIRED => LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
        LegacyRmeLifecycleRefusal::PERMISSION_DENIED => LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
        LegacyRmeLifecycleRefusal::POLICY_DENIED => LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
        LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES => LegacyBatchPublishReason::SOD_REFUSED,
        LegacyRmeLifecycleRefusal::TRANSITION_NOT_ALLOWED => LegacyBatchPublishReason::NOT_REVIEWED,
    ];

    /**
     * Validation field keys whose meaning is unambiguous.
     *
     * `actor` is the separation-of-duties field and is used for nothing else in
     * either module, which is why SOD classification is EXACT rather than
     * best-effort — and SOD is the one refusal an operator must act on by
     * handing the document to a different publisher.
     */
    private const FIELD_MAP = [
        'actor' => LegacyBatchPublishReason::SOD_REFUSED,
        'origin_branch_id' => LegacyBatchPublishReason::BRANCH_REFUSED,
        'patient_id' => LegacyBatchPublishReason::BRANCH_REFUSED,
        LegacyRmeSourceRmFailure::FIELD => LegacyBatchPublishReason::PATIENT_BINDING_FAILED,
        'source_rm' => LegacyBatchPublishReason::PATIENT_BINDING_FAILED,
        'source_rm_normalized' => LegacyBatchPublishReason::PATIENT_BINDING_FAILED,
        'verification_visit_id' => LegacyBatchPublishReason::SOURCE_CHANGED,
        'verification_visit_date' => LegacyBatchPublishReason::SOURCE_CHANGED,
        LegacyRmeDateRuleService::FIELD => LegacyBatchPublishReason::DATE_RULE_FAILED,
        LegacyRmeDateRuleService::FIELD_LATEST => LegacyBatchPublishReason::DATE_RULE_FAILED,
        LegacyOdontogramDateRuleService::FIELD => LegacyBatchPublishReason::DATE_RULE_FAILED,
        'status' => LegacyBatchPublishReason::STALE_ITEM,
    ];

    public static function classify(Throwable $exception): LegacyBatchPublishOutcome
    {
        // The patient's single-active-document slot. Its own exception type, so
        // this is exact rather than inferred.
        if ($exception instanceof LegacyDocumentSlotOccupied) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::SINGLE_ACTIVE_CONFLICT,
                'Pasien sudah memiliki dokumen aktif untuk jenis arsip ini.'
            );
        }

        // RME hands us its own refusal code — the most precise path available.
        if ($exception instanceof LegacyRmeLifecycleDenied) {
            $code = self::LIFECYCLE_CODE_MAP[$exception->refusalCode]
                ?? LegacyBatchPublishReason::AUTHORIZATION_REFUSED;

            return LegacyBatchPublishOutcome::refused($code, self::safeMessage($exception->getMessage()));
        }

        if ($exception instanceof ValidationException) {
            return self::classifyValidation($exception);
        }

        // The feature guard aborts 404 when migration capability is off, and an
        // out-of-scope import also resolves to 404 by design so an actor cannot
        // probe which ids exist in a branch they cannot see.
        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                404 => LegacyBatchPublishOutcome::refused(
                    LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
                    'Dokumen tidak tersedia atau kapabilitas migrasi tidak aktif.'
                ),
                403 => LegacyBatchPublishOutcome::refused(
                    LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
                    'Tidak berwenang mempublikasikan dokumen ini.'
                ),
                default => LegacyBatchPublishOutcome::refused(
                    LegacyBatchPublishReason::PUBLISH_UNKNOWN,
                    self::safeMessage($exception->getMessage())
                ),
            };
        }

        if ($exception instanceof AuthorizationException) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
                self::safeMessage($exception->getMessage()) ?? 'Tidak berwenang mempublikasikan dokumen ini.'
            );
        }

        // Anything else. Classified as unknown WITH the canonical message —
        // never dropped, never treated as published.
        return LegacyBatchPublishOutcome::refused(
            LegacyBatchPublishReason::PUBLISH_UNKNOWN,
            self::safeMessage($exception->getMessage())
        );
    }

    private static function classifyValidation(ValidationException $exception): LegacyBatchPublishOutcome
    {
        $errors = $exception->errors();
        $message = null;

        foreach ($errors as $messages) {
            if (is_array($messages) && $messages !== []) {
                $message = self::safeMessage((string) reset($messages));

                break;
            }
        }

        // Specific fields win over the catch-all `status`, so a refusal that
        // reports both reads as the more actionable one.
        foreach (array_keys($errors) as $field) {
            $code = self::FIELD_MAP[(string) $field] ?? null;

            if ($code !== null && $code !== LegacyBatchPublishReason::STALE_ITEM) {
                return LegacyBatchPublishOutcome::refused($code, $message);
            }
        }

        if (array_key_exists('status', $errors)) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::STALE_ITEM,
                $message
            );
        }

        return LegacyBatchPublishOutcome::refused(
            LegacyBatchPublishReason::PUBLISH_UNKNOWN,
            $message
        );
    }

    /**
     * Bound the stored message and keep anything structural out of it.
     *
     * Canonical messages are already operator-safe Indonesian prose; this is a
     * belt-and-braces guard so an unexpected exception type cannot write a
     * stack frame, a filesystem path or a SQL fragment into an operator-visible
     * column — which §11 forbids outright.
     *
     * NOTE: every alternative below must be NON-EMPTY. An empty trailing
     * alternative matches any string and would silently replace every canonical
     * message with the generic fallback — a bug PR1 shipped and caught.
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

        $structural = '#(?:/[A-Za-z0-9_.\-]+){2,}'   // a filesystem-ish path
            .'|\bselect\b.+?\bfrom\b'                 // a SQL fragment
            .'|SQLSTATE'                              // a PDO error
            .'|\bexception\b'                         // an exception class name
            .'|::'                                    // a PHP symbol reference
            .'|\$[A-Za-z_]'                           // a PHP variable
            .'#i';

        if (preg_match($structural, $trimmed) === 1) {
            return 'Ditolak oleh sistem. Hubungi administrator jika berulang.';
        }

        return mb_substr($trimmed, 0, 500);
    }
}
