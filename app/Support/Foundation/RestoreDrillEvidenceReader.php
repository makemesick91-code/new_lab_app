<?php

namespace App\Support\Foundation;

/**
 * RESTORE-DRILL-EVIDENCE-READ-STATE-1 — canonical read-state boundary for
 * restore-drill evidence.
 *
 * Restore-drill evidence is operational safety evidence. Before anything can be
 * said about what the evidence CONTAINS, the system has to know whether the
 * bytes were obtained at all — and those are different questions with different
 * remediations. Reading nothing because the file is not there, because the
 * process may not read it, or because the read itself failed are three distinct
 * operational faults; none of them is a malformed document.
 *
 * The defect this class exists to prevent: `(string) @file_get_contents($path)`
 * turns a failed read (`false`) into an empty string, and an empty string is
 * then indistinguishable from a file that genuinely contained nothing. By the
 * time the JSON decoder sees it, the only fact left is "this did not parse", so
 * every upstream fault — unreadable, vanished mid-read, I/O error — was reported
 * to the operator as "invalid JSON". The readiness verdict stayed correct (all
 * of them are non-GO), but the operator was told to fix the document's format
 * when the actual fault was a permission or an I/O failure.
 *
 * So the read outcome is decided HERE, once, before any content is interpreted,
 * and `false` is never coerced to a string. Error suppression on the read is
 * noise control only — the control flow is the explicit `=== false` comparison,
 * never the presence or absence of a warning.
 *
 * Deliberately NOT a general-purpose filesystem abstraction: it exists so the
 * restore-drill evidence pipeline can name its own read faults, and it is
 * injectable so an I/O failure that a test cannot reliably provoke on a real
 * filesystem can still be exercised deterministically.
 */
class RestoreDrillEvidenceReader
{
    /** No file exists at the path. */
    public const READ_ABSENT = 'absent';

    /** A file exists but this process is not permitted to read it. */
    public const READ_UNREADABLE = 'unreadable';

    /** The read was attempted and failed (I/O error, or the file changed under us). */
    public const READ_FAILED = 'read_failed';

    /** The read succeeded and returned zero bytes. */
    public const READ_EMPTY = 'empty';

    /** The read succeeded and returned content. */
    public const READ_OK = 'ok';

    /**
     * Read one evidence file and report WHY it could not be read, when it could not.
     *
     * `contents` is non-null only for READ_EMPTY (always '') and READ_OK. It is
     * null for every failure state so a caller cannot mistake "read nothing" for
     * "read an empty document".
     *
     * @return array{state: string, contents: ?string}
     */
    public function read(string $absolutePath): array
    {
        // Checked before the read so the common faults are named precisely. The
        // window between these checks and the read is real (the file can vanish
        // or lose permissions in between), which is exactly why the read result
        // is still inspected explicitly below rather than assumed to succeed.
        if (! is_file($absolutePath)) {
            return ['state' => self::READ_ABSENT, 'contents' => null];
        }

        if (! is_readable($absolutePath)) {
            return ['state' => self::READ_UNREADABLE, 'contents' => null];
        }

        // Suppression keeps a failed read from emitting a PHP warning; it is not
        // the state model. The state is the explicit `false` comparison below.
        $raw = @file_get_contents($absolutePath);

        if ($raw === false) {
            return ['state' => self::READ_FAILED, 'contents' => null];
        }

        if ($raw === '') {
            return ['state' => self::READ_EMPTY, 'contents' => ''];
        }

        return ['state' => self::READ_OK, 'contents' => $raw];
    }
}
