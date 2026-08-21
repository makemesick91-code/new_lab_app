<?php

// POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-02
//
// A 2.9MB SQLite database was committed to the repository root and lived there
// undetected. It was schema-only — no patient, user, clinical, payment or audit
// row, and no secret — so it was a hygiene defect rather than a disclosure. The
// next one might not be.
//
// THE MECHANISM, so the guard is aimed at the cause and not at one filename:
// config/database.php resolves the sqlite connection's file from DB_DATABASE
// (`env('DB_DATABASE', database_path('database.sqlite'))`). A local environment
// file legitimately sets DB_DATABASE to the PostgreSQL database NAME
// `asia_dental_lab`, so ANY command run with DB_CONNECTION=sqlite resolves that
// name as a path relative to the working directory and writes a real SQLite
// database there. The filename is whatever DB_DATABASE happens to say, so an
// ignore rule for one spelling cannot be the whole defence.
//
// This asserts the invariant that actually matters — no SQLite database is
// TRACKED at the repository root — by file CONTENT, not by name or extension.
// The artifact had no extension at all, so `*.sqlite` / `*.db` would have
// missed it entirely.

use Illuminate\Support\Facades\Process;

/** The 16-byte header every SQLite 3 database starts with. */
const SQLITE3_MAGIC = "SQLite format 3\0";

/**
 * Repository-root entries that git currently tracks.
 *
 * @return list<string>
 */
function trackedRepositoryRootFiles(): array
{
    $result = Process::path(base_path())->run('git ls-files -z --full-name .');

    if (! $result->successful()) {
        return [];
    }

    $paths = array_filter(explode("\0", $result->output()), fn (string $p) => $p !== '');

    // Root level only: no directory separator in the repo-relative path.
    return array_values(array_filter($paths, fn (string $p) => ! str_contains($p, '/')));
}

it('has git available so this guard is a real check and not a silent pass', function () {
    expect(Process::path(base_path())->run('git rev-parse --is-inside-work-tree')->successful())
        ->toBeTrue('git must be available for the repository hygiene guard to mean anything');
});

it('tracks no SQLite database at the repository root', function () {
    $tracked = trackedRepositoryRootFiles();

    expect($tracked)->not->toBeEmpty('expected git to report tracked root files');

    $offenders = [];

    foreach ($tracked as $relativePath) {
        $absolute = base_path($relativePath);

        if (! is_file($absolute) || filesize($absolute) < strlen(SQLITE3_MAGIC)) {
            continue;
        }

        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            continue;
        }

        $header = fread($handle, strlen(SQLITE3_MAGIC));
        fclose($handle);

        if ($header === SQLITE3_MAGIC) {
            $offenders[] = $relativePath;
        }
    }

    expect($offenders)->toBe([], sprintf(
        'A SQLite database is tracked at the repository root: %s. '
        .'This is a runtime artifact, not source. Untrack it with `git rm --cached <file>`, '
        .'add an anchored ignore rule, and check whether a command ran with DB_CONNECTION=sqlite '
        .'while DB_DATABASE still held a PostgreSQL database name.',
        implode(', ', $offenders)
    ));
});

it('no longer tracks the asia_dental_lab artifact and ignores it if it reappears', function () {
    expect(trackedRepositoryRootFiles())->not->toContain('asia_dental_lab');

    // The anchored rule must actually catch it, so a stray recreation stays out
    // of `git status` instead of being committed again by a broad `git add -A`.
    $ignored = Process::path(base_path())->run('git check-ignore -q asia_dental_lab');

    expect($ignored->exitCode())->toBe(0, '/asia_dental_lab must be ignored by .gitignore');
});

/*
 * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — the consent-free create
 * primitive must stay out of production code.
 *
 * `OdontogramService::getOrCreateForVisit()` checks the RME branch but does NOT
 * assert consent, unlike `saveForVisit()`. It survives only as a test fixture
 * primitive. Raised by adversarial review: a docblock saying "no production
 * caller" is a convention, and this sprint's whole thesis is that a
 * documented-but-reachable create path is what produced the defect. So the
 * claim is enforced here instead.
 */
it('keeps the consent-free odontogram create primitive out of production code', function () {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // The definition itself, and the controller comment that explains why
        // the read path no longer calls it, are the two legitimate mentions.
        if (str_ends_with($path, 'Odontogram/Services/OdontogramService.php')
            || str_ends_with($path, 'Odontogram/Controllers/OdontogramController.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), 'getOrCreateForVisit')) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([],
        'getOrCreateForVisit() does not assert consent and must not be called from application code. '
        .'Use OdontogramService::saveForVisit(), which gates the insert on a signed consent. Offenders: '
        .implode(', ', $offenders)
    );
});

it('keeps the PostgreSQL database name asia_dental_lab untouched', function () {
    // The FILE was the defect; the DATABASE NAME of the same spelling is
    // legitimate configuration and several scripts and the environment example
    // depend on it. Deleting the artifact must never have edited those.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('DB_DATABASE=asia_dental_lab');
});
