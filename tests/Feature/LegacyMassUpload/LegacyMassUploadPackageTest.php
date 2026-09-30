<?php

declare(strict_types=1);

use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadManifestParser;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadPackageService;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadPackageContents;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §45 — package integrity.
 *
 * These run against a REAL ZipArchive on a REAL (faked-root) local disk. A
 * mocked archive could not demonstrate Zip Slip, symlink entries, byte caps or
 * magic-byte validation, which are precisely the properties under test.
 */
beforeEach(function (): void {
    legacyMassUploadFakeDisk();

    $this->packages = app(LegacyMassUploadPackageService::class);
    $this->parser = app(LegacyMassUploadManifestParser::class);
});

/**
 * Run the full intake path and return the validated contents.
 */
function lmuValidate(
    UploadedFile $zip,
    string $importType = LegacyImportType::LEGACY_RME,
): LegacyMassUploadPackageContents {
    $packages = app(LegacyMassUploadPackageService::class);
    $parser = app(LegacyMassUploadManifestParser::class);

    $uuid = (string) Str::uuid();
    $stored = $packages->storePackage($zip, $uuid);

    return $packages->validateAndExtract($stored['path'], $uuid, $parser, $importType);
}

/**
 * Assert the archive is refused with a specific reason code.
 */
function lmuExpectRejection(
    UploadedFile $zip,
    string $reasonCode,
    string $importType = LegacyImportType::LEGACY_RME,
): void {
    try {
        lmuValidate($zip, $importType);
    } catch (LegacyMassUploadPackageRejected $rejected) {
        expect($rejected->reasonCode)->toBe($reasonCode);

        // No refusal may leak an internal path, a disk name or a class name.
        expect($rejected->safeMessage)
            ->not->toContain('/')
            ->not->toContain('Exception')
            ->not->toContain('legacy_mass_upload_private');

        return;
    }

    throw new RuntimeException('Expected package rejection ['.$reasonCode.'] but the archive was accepted.');
}

it('accepts a well formed archive and maps every manifest row to a document', function (): void {
    $zip = legacyMassUploadZip(
        documents: [
            'a.pdf' => legacyRmePdfBytes(1),
            'b.pdf' => legacyRmePdfBytes(2),
        ],
        manifestRows: [
            legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02', '2019-05-06'),
            legacyMassUploadRmeRow('RM-002', 'b.pdf', '2017-03-04'),
        ],
    );

    $contents = lmuValidate($zip);

    expect($contents->rowCount())->toBe(2)
        ->and($contents->documents)->toHaveCount(2)
        ->and($contents->manifestSha256)->toHaveLength(64)
        ->and($contents->hasUnreferencedDocuments())->toBeFalse();

    $first = $contents->rows[0];
    expect($first->medicalRecordNumber)->toBe('RM-001')
        ->and($first->fileName)->toBe('a.pdf')
        ->and($first->selectedDate)->toBe('2018-01-02')
        ->and($first->latestDate)->toBe('2019-05-06');

    // The second row declares no latest date; that is a single-date document,
    // not a missing input, and must stay null rather than be defaulted.
    expect($contents->rows[1]->latestDate)->toBeNull();
});

it('writes extracted documents to generated names, never the archive supplied name', function (): void {
    // THE STRUCTURAL ZIP SLIP PROOF.
    //
    // A traversing name is refused outright (covered below), so this uses a
    // merely unusual one to show the mechanism: whatever the archive calls an
    // entry, the file on disk is named by us.
    $zip = legacyMassUploadZip(
        documents: ['nested/folder/weird name.pdf' => legacyRmePdfBytes(1)],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'weird name.pdf', '2018-01-02')],
    );

    $contents = lmuValidate($zip);

    $document = $contents->documentFor('weird name.pdf');

    expect($document)->not->toBeNull()
        // Logical name is the manifest key…
        ->and($document->logicalName)->toBe('weird name.pdf')
        // …but the path is ours: sequential, sanitised by construction.
        ->and(basename($document->relativePath))->toBe('000001.pdf')
        ->and($document->relativePath)->toContain('/docs/')
        ->and($document->absolutePath)->toEndWith('000001.pdf')
        ->and(is_file($document->absolutePath))->toBeTrue()
        ->and($document->sha256)->toHaveLength(64);
});

it('refuses an archive with no manifest', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['omit_manifest' => true],
        ),
        LegacyMassUploadReason::MANIFEST_MISSING,
    );
});

it('refuses a manifest whose required header is missing', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['manifest_raw' => "medical_record_number,file_name\nRM-001,a.pdf\n"],
        ),
        LegacyMassUploadReason::MANIFEST_HEADER_INVALID,
    );
});

it('refuses a manifest carrying an identity column', function (): void {
    // NIK must never reach a staging table or a downloadable report, so the
    // column is refused by name rather than merely ignored.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['manifest_raw' => "medical_record_number,file_name,rme_date_earliest,nik\nRM-001,a.pdf,2018-01-02,3273010101010001\n"],
        ),
        LegacyMassUploadReason::MANIFEST_HEADER_FORBIDDEN,
    );
});

it('refuses a manifest that tries to name a branch', function (): void {
    // Branch is an authorization boundary and is resolved server-side from the
    // patient. A file an operator edits must not be able to move a document
    // into another branch's scope.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['manifest_raw' => "medical_record_number,file_name,rme_date_earliest,branch_id\nRM-001,a.pdf,2018-01-02,2\n"],
        ),
        LegacyMassUploadReason::MANIFEST_HEADER_FORBIDDEN,
    );
});

it('refuses a manifest that tries to name a patient id', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['manifest_raw' => "medical_record_number,file_name,rme_date_earliest,patient_id\nRM-001,a.pdf,2018-01-02,99\n"],
        ),
        LegacyMassUploadReason::MANIFEST_HEADER_FORBIDDEN,
    );
});

it('refuses a manifest with no data rows', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            options: ['manifest_raw' => "medical_record_number,file_name,rme_date_earliest\n"],
        ),
        LegacyMassUploadReason::MANIFEST_EMPTY,
    );
});

it('refuses a manifest that references a document not in the archive', function (): void {
    // A partial archive must not import its subset while reporting success for
    // the rows that happened to be present.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [
                legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02'),
                legacyMassUploadRmeRow('RM-002', 'missing.pdf', '2018-01-02'),
            ],
        ),
        LegacyMassUploadReason::MANIFEST_FILE_MISSING,
    );
});

it('refuses a manifest claiming one document twice', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [
                legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02'),
                legacyMassUploadRmeRow('RM-002', 'a.pdf', '2018-01-02'),
            ],
        ),
        LegacyMassUploadReason::MANIFEST_DUPLICATE_FILE,
    );
});

it('refuses a traversing entry name', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['../../../../etc/cron.d/payload.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'payload.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::PACKAGE_UNSAFE_PATH,
    );
});

it('refuses an absolute entry name', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['/etc/passwd.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'passwd.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::PACKAGE_ABSOLUTE_PATH,
    );
});

it('refuses a backslash separated entry name', function (): void {
    // Not a legal ZIP separator, and a known way to smuggle traversal past a
    // forward-slash-only check.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['..\\..\\escape.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'escape.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::PACKAGE_UNSAFE_PATH,
    );
});

it('refuses a symlink entry', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['link.pdf' => '/etc/shadow'],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'link.pdf', '2018-01-02')],
            options: ['symlinks' => ['link.pdf']],
        ),
        LegacyMassUploadReason::PACKAGE_SYMLINK_ENTRY,
    );
});

it('refuses two entries whose leaf names collide', function (): void {
    // The manifest matches on the leaf name, so a collision cannot be mapped
    // deterministically and must not become last-one-wins.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: [
                'satu/a.pdf' => legacyRmePdfBytes(1),
                'dua/a.pdf' => legacyRmePdfBytes(2),
            ],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::PACKAGE_DUPLICATE_ENTRY,
    );
});

it('refuses a document that is not really a pdf', function (): void {
    // The .pdf extension came from the archive and proves nothing; the magic
    // bytes decide.
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => "GIF89a\x00 this is not a pdf at all"],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::FILE_NOT_PDF,
    );
});

it('refuses a corrupted archive', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
            options: ['corrupt' => true],
        ),
        LegacyMassUploadReason::PACKAGE_UNREADABLE,
    );
});

it('refuses an archive with more entries than the configured ceiling', function (): void {
    config()->set('legacy_mass_upload.package.max_entries', 3);

    $documents = [];
    $rows = [];

    for ($i = 1; $i <= 6; $i++) {
        $documents["doc{$i}.pdf"] = legacyRmePdfBytes(1);
        $rows[] = legacyMassUploadRmeRow("RM-00{$i}", "doc{$i}.pdf", '2018-01-02');
    }

    lmuExpectRejection(
        legacyMassUploadZip(documents: $documents, manifestRows: $rows),
        LegacyMassUploadReason::PACKAGE_TOO_MANY_ENTRIES,
    );
});

it('refuses a document larger than the per document ceiling', function (): void {
    config()->set('legacy_mass_upload.package.document_max_bytes', 512);

    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(40)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
        ),
        // Caught in the central-directory audit, before extraction.
        LegacyMassUploadReason::PACKAGE_UNCOMPRESSED_TOO_LARGE,
    );
});

it('ignores macos resource fork noise instead of failing the batch', function (): void {
    // Present in a large share of operator-produced archives. Failing a real
    // migration over it would be a self-inflicted support burden.
    $zip = legacyMassUploadZip(
        documents: [
            'a.pdf' => legacyRmePdfBytes(1),
            '__MACOSX/._a.pdf' => 'resource fork junk',
            '.DS_Store' => 'finder junk',
        ],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
    );

    $contents = lmuValidate($zip);

    expect($contents->rowCount())->toBe(1)
        ->and($contents->documents)->toHaveCount(1);
});

it('reports an unreferenced document as a warning rather than refusing the archive', function (): void {
    // The operator must be told, because a document they believe was migrated
    // and silently was not is the genuinely dangerous outcome.
    $zip = legacyMassUploadZip(
        documents: [
            'a.pdf' => legacyRmePdfBytes(1),
            'stray.pdf' => legacyRmePdfBytes(1),
        ],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
    );

    $contents = lmuValidate($zip);

    expect($contents->rowCount())->toBe(1)
        ->and($contents->hasUnreferencedDocuments())->toBeTrue()
        ->and($contents->unreferencedDocuments)->toContain('stray.pdf');
});

it('skips trailing blank manifest lines that spreadsheet exports append', function (): void {
    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1)],
        options: ['manifest_raw' => "medical_record_number,file_name,rme_date_earliest\nRM-001,a.pdf,2018-01-02\n,,\n,,\n"],
    );

    $contents = lmuValidate($zip);

    expect($contents->rowCount())->toBe(1)
        ->and($contents->rows[0]->rowNumber)->toBe(1);
});

it('strips the excel byte order mark from the first header cell', function (): void {
    // Without this the first column never compares equal to itself and every
    // Excel-exported manifest would be refused.
    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1)],
        options: ['manifest_raw' => "\xEF\xBB\xBFmedical_record_number,file_name,rme_date_earliest\nRM-001,a.pdf,2018-01-02\n"],
    );

    expect(lmuValidate($zip)->rowCount())->toBe(1);
});

it('uses the odontogram manifest contract for the odontogram surface', function (): void {
    $zip = legacyMassUploadZip(
        documents: ['chart.pdf' => legacyRmePdfBytes(1)],
        manifestRows: [legacyMassUploadOdontogramRow('RM-001', 'chart.pdf', '2019-04-11')],
        importType: LegacyImportType::LEGACY_ODONTOGRAM,
    );

    $contents = lmuValidate($zip, LegacyImportType::LEGACY_ODONTOGRAM);

    expect($contents->rowCount())->toBe(1)
        ->and($contents->rows[0]->selectedDate)->toBe('2019-04-11')
        // Odontogram has no date range; latest must stay null rather than be
        // invented from the single date.
        ->and($contents->rows[0]->latestDate)->toBeNull();
});

it('refuses an rme manifest submitted to the odontogram surface', function (): void {
    lmuExpectRejection(
        legacyMassUploadZip(
            documents: ['a.pdf' => legacyRmePdfBytes(1)],
            manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
        ),
        LegacyMassUploadReason::MANIFEST_HEADER_INVALID,
        LegacyImportType::LEGACY_ODONTOGRAM,
    );
});

it('removes the workspace when cleanup runs', function (): void {
    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1)],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
    );

    $uuid = (string) Str::uuid();
    $stored = $this->packages->storePackage($zip, $uuid);
    $this->packages->validateAndExtract($stored['path'], $uuid, $this->parser, LegacyImportType::LEGACY_RME);

    $disk = Storage::disk('legacy_mass_upload_private');
    $dir = $this->packages->workspaceRelativeDir($uuid);

    expect($disk->directoryExists($dir))->toBeTrue();

    $this->packages->cleanWorkspace($uuid);

    expect($disk->directoryExists($dir))->toBeFalse();

    // Idempotent: cleaning an already-clean workspace must not throw, because
    // it runs on the success path, the failure path and the cancel path.
    $this->packages->cleanWorkspace($uuid);

    expect($disk->directoryExists($dir))->toBeFalse();
});

it('refuses to use a public disk for the workspace', function (): void {
    config()->set('legacy_mass_upload.workspace.disk', 'public');

    expect(fn () => app(LegacyMassUploadPackageService::class)->disk())
        ->toThrow(RuntimeException::class);
});
