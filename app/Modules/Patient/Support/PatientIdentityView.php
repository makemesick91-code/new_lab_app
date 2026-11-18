<?php

namespace App\Modules\Patient\Support;

use App\Modules\Patient\Services\PatientIdentityPresenter;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 (rule 243 §3) — a patient's
 * identity as ONE viewer may see it. Built only by
 * {@see PatientIdentityPresenter::forViewer()}; a screen renders it and decides
 * nothing (CG-04).
 *
 * The doctor audience carries the memo list: name, RM number, place and date
 * of birth with the age, occupation and gender (planned treatment is the
 * visit's "Tindakan Awal", rendered from the visit). The phone, WhatsApp
 * number, email and address are null for it, and `biodataRows` never names
 * them. No audience carries the NIK: the only screens that render one are the
 * permission-gated master form and the consent document (CG-11).
 */
final readonly class PatientIdentityView
{
    /**
     * `$biodataRows` is the RM workspace's "Informasi Kunjungan" table, one
     * [label, value, label|null, value|null] per row.
     *
     * @param  list<array{0: string, 1: string, 2: ?string, 3: ?string}>  $biodataRows
     */
    public function __construct(
        public string $audience,
        public ?string $name,
        public ?string $medicalRecordNumber,
        public ?string $birthPlaceDateAge,
        public ?string $occupation,
        public ?string $gender,
        public ?string $phone,
        public ?string $whatsappNumber,
        public ?string $email,
        public ?string $address,
        public array $biodataRows,
    ) {}

    /** False for the doctor audience: the phone, WhatsApp, email and address rows are not rendered. */
    public function showsContact(): bool
    {
        return $this->audience === PatientIdentityPresenter::AUDIENCE_FULL;
    }
}
