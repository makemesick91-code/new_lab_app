{{--
    FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — the signed PERSETUJUAN
    TINDAKAN MEDIS, laid out to follow the clinic's printed form.

    Everything textual comes from $consent->content_snapshot, NOT from the live
    config: this is the wording the patient actually agreed to, frozen at signing
    time, so editing the template later can never rewrite history.

    Table-based on purpose (dompdf): no flexbox, no grid.

    This is the ONE surface in the application that renders a full identity
    number. That is deliberate and narrow — it is a legal document that records
    "No KTP/SIM", it is reachable only through the consent policy (permission +
    branch scope + patient scope), and the value is never logged, never listed
    and never serialised (the model marks it $hidden).
--}}
@php
    $snapshot = $consent->content_snapshot ?? [];
    $clauses = $snapshot['clauses'] ?? [];
    $documentationClause = $snapshot['documentation_clause'] ?? null;
    $relationshipLabel = $relationships[$consent->consenter_relationship] ?? $consent->consenter_relationship;
    $genderLabel = fn (?string $gender) => $gender ? ($genderLabels[$gender] ?? $gender) : '—';
    $ageGender = function (?string $age, ?string $gender) use ($genderLabel) {
        $age = trim((string) $age);

        return ($age !== '' ? $age.' tahun' : '—').' / '.$genderLabel($gender);
    };

    // Rule 241 §1.6 — the treating doctor's countersignature (its own record).
    // The controller decides both values; the template only renders them:
    //  - $doctorCountersignature: the record, or null;
    //  - $doctorCountersignaturePending: true only while a countersignature is
    //    still possible (live consent, visit in progress, none yet).
    // A historical consent (neither) renders byte for byte as before rule 241,
    // which is why the additions below use column-0 directives and no Blade
    // comments: they emit nothing when they do not apply. The legacy optional
    // doctor drawing ($doctorSignature) is display history and always renders
    // as it did, also beside a countersignature.
    $doctorCountersignature = $doctorCountersignature ?? null;
    $doctorCountersignaturePending = (bool) ($doctorCountersignaturePending ?? false);

    // Rule 241 §6 — v2026.2 answers clause 9 (publication) separately; the
    // snapshot says so. A version without it (2026.1 and older) renders byte for
    // byte as before: the addition below is a column-0 directive that emits
    // nothing (pinned against tests/Fixtures/rme-consent-document-pre-v2026-2.blade.php.txt).
    $publicationClause = $snapshot['publication_clause'] ?? null;
@endphp

<div class="consent-document">

    <div class="consent-title">{{ $snapshot['title'] ?? 'PERSETUJUAN TINDAKAN MEDIS' }}</div>
    <div class="consent-number">No : {{ $consent->consent_number }}</div>

    @if ($consent->isVoided())
        <div class="consent-void-stamp">
            DIBATALKAN — {{ $consent->voided_at?->format('d/m/Y H:i') }}
            @if (filled($consent->void_reason))
                <br>Alasan: {{ $consent->void_reason }}
            @endif
        </div>
    @endif

    <p class="consent-lead">Yang bertanda tangan dibawah ini :</p>

    <table class="consent-identity">
        <tr>
            <td class="consent-label">Nama</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->consenter_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">Umur/Jenis Kelamin</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $ageGender($consent->consenter_age, $consent->consenter_gender) }}</td>
        </tr>
        <tr>
            <td class="consent-label">Alamat</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->consenter_address ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">No KTP/SIM</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->consenter_identity_number ?: '—' }}</td>
        </tr>
    </table>

    <p class="consent-statement">
        {{ $snapshot['consent_statement'] ?? 'Dengan ini menyatakan Persetujuan untuk diberikan tindakan medis berupa' }}
        <span class="consent-inline-value">{{ $consent->medical_action ?: '—' }}</span>
        terhadap :
    </p>

    <p class="consent-relationship">
        <span class="consent-inline-value">{{ $relationshipLabel }}</span> — dengan :
    </p>

    <table class="consent-identity">
        <tr>
            <td class="consent-label">Nama</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->patient_name_snapshot ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">Umur/Jenis Kelamin</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $ageGender($consent->patient_age_snapshot, $consent->patient_gender_snapshot) }}</td>
        </tr>
        <tr>
            <td class="consent-label">Alamat</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->patient_address_snapshot ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">No KTP/SIM</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->patient_identity_number_snapshot ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">Jenis Perawatan</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->treatment_summary ?: '—' }}</td>
        </tr>
        <tr>
            <td class="consent-label">No. Dental Record</td>
            <td class="consent-sep">:</td>
            <td class="consent-value">{{ $consent->medical_record_number_snapshot ?: '—' }}</td>
        </tr>
    </table>

    <p class="consent-lead">{{ $snapshot['clauses_intro'] ?? 'Adapun hal-hal yang harus/wajib disetujui dalam surat persetujuan ini sebagai berikut:' }}</p>

    <table class="consent-clauses">
        @foreach ($clauses as $number => $clause)
            <tr>
                <td class="consent-clause-number">{{ $number }}.</td>
                <td class="consent-clause-text">
                    {{ $clause }}
                    @if ($documentationClause !== null && (int) $number === (int) $documentationClause)
                        <span class="consent-documentation-answer">
                            Jawaban: {{ $consent->documentation_consent ? 'YA' : 'TIDAK' }}
                        </span>
                    @endif
@if ($publicationClause !== null && (int) $number === (int) $publicationClause)
                        <span class="consent-documentation-answer">
                            Jawaban: {{ $consent->publication_consent === null ? '—' : ($consent->publication_consent ? 'YA' : 'TIDAK') }}
                        </span>
@endif
                </td>
            </tr>
        @endforeach
    </table>

    <p class="consent-declaration">{{ $snapshot['declaration'] ?? 'Demikian persetujuan ini saya buat dengan penuh kesadaran dan tanpa paksaan apapun.' }}</p>

    <table class="consent-signatures">
        <tr>
            <td class="consent-signature-cell"></td>
            <td class="consent-signature-cell consent-place-date">
                {{ $consent->signed_location ?: 'Makassar' }}, {{ $consent->signed_at?->translatedFormat('d F Y') }}
            </td>
        </tr>
        <tr>
            <td class="consent-signature-cell consent-signature-heading">
                {{ $snapshot['signature_labels']['doctor'] ?? 'Dokter' }}
            </td>
            <td class="consent-signature-cell consent-signature-heading">
                {{ $snapshot['signature_labels']['consenter'] ?? 'Yang membuat persetujuan' }}
            </td>
        </tr>
        <tr>
            <td class="consent-signature-cell consent-signature-box">
                @if (! empty($doctorSignature))
                    <img src="{{ $doctorSignature }}" alt="Tanda tangan dokter" class="consent-signature-image">
                @endif
@if ($doctorCountersignature)
                <div class="consent-countersignature" data-testid="consent-doctor-countersignature">
                    <div class="consent-signature-meta">Tanda tangan dokter (PTM)</div>
@if (! empty($doctorCountersignatureImage))
                    <img src="{{ $doctorCountersignatureImage }}" alt="Tanda tangan dokter (PTM)" class="consent-signature-image">
@else
                    <span class="consent-signature-note">Gambar tanda tangan dokter tidak tersedia.</span>
@endif
                </div>
@elseif ($doctorCountersignaturePending)
                <span class="consent-signature-note">Belum ditandatangani dokter</span>
@endif
            </td>
            <td class="consent-signature-cell consent-signature-box">
                @if (! empty($consenterSignature))
                    <img src="{{ $consenterSignature }}" alt="Tanda tangan pemberi persetujuan" class="consent-signature-image">
                @endif
            </td>
        </tr>
        <tr>
            <td class="consent-signature-cell consent-signature-name">
                {{ $consent->doctor_name_snapshot ?: '—' }}
@if ($doctorCountersignature)
                <div class="consent-signature-meta">
                    Tanda tangan dokter (PTM): {{ $doctorCountersignature->doctor?->name ?? ($consent->doctor_name_snapshot ?: '—') }},
                    {{ $doctorCountersignature->signed_at?->format('d/m/Y H:i') }} WITA
                </div>
@endif
            </td>
            <td class="consent-signature-cell consent-signature-name">
                {{ $consent->consenter_name ?: '—' }}
            </td>
        </tr>
    </table>

</div>
