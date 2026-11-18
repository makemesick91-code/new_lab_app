{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 RM PR-5 (memo M4.2, rule 244 §5.9) — the
     place of birth a KTP read offers beside the "Tempat Lahir" field of a NEW
     patient (Master Data → Tambah Pasien, Kunjungan → Pasien Baru).

     Rendered empty and hidden; resources/js/ktp-camera-ocr.js
     (createPlaceOfBirthSuggestion) writes the read with textContent only and
     shows it when it passes the server's single-line rule. Only "Gunakan"
     writes it into the field — never automatically — and then asks for the KTP
     attestation like every applied OCR value. No inline script, no URL. --}}
<p class="mt-1 hidden text-xs text-indigo-700" data-ktp-pob-suggestion aria-live="polite">
    Saran dari KTP: <span class="font-medium" data-ktp-pob-suggestion-value></span> —
    <button type="button" class="font-medium underline hover:text-indigo-900" data-ktp-pob-suggestion-use>Gunakan</button>
    <span class="hidden text-amber-700" data-ktp-pob-suggestion-replaces>(mengganti isian)</span>
</p>
