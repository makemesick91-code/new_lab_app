@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeAmendment\Models\RmeRecordVersion;
@endphp
{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 (rule 240 §6) — the "Amandemen"
     block of a FINAL sheet or a FINALIZED chart. `$amendmentPanel` comes from
     RmeAmendmentService::panelFor() (null on a draft: nothing renders). The
     request offer mirrors the server (rule 135): `can_request` is the service's
     own answer, so a Super Admin who is neither the doctor of record nor the
     finalizer is not offered a request the service would refuse. A link to an
     amendment renders only for an account that may open it. --}}
@if (($amendmentPanel ?? null) !== null)
    @php
        $amendmentIsChart = $amendmentPanel['subject_type'] === RmeRecordVersion::SUBJECT_ODONTOGRAM;
        $amendmentPending = $amendmentPanel['pending'];
    @endphp
    <div data-amendment-panel>
        <x-ui.card title="Amandemen" description="Rekam medis final hanya dapat dikoreksi melalui amandemen dengan alasan tertulis yang disetujui Supervisor RME.">
            @if ($amendmentPending !== null)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" data-amendment-status="pending">
                    Permintaan amandemen #{{ $amendmentPending->id }} menunggu persetujuan Supervisor RME
                    — diajukan oleh {{ $amendmentPending->requester?->name ?? 'akun tidak dikenal' }}
                    pada {{ OversightFormat::clinicalTime($amendmentPending->created_at, 'd/m/Y H:i') }} WITA.
                    Rekam medis belum berubah.
                    @if (in_array((int) $amendmentPending->id, $amendmentPanel['viewable'], true))
                        <a class="font-semibold text-brand-700 hover:underline" href="{{ route('rme.amendments.show', $amendmentPending) }}">Lihat permintaan</a>
                    @endif
                </div>
            @endif

            @php $amendmentHistory = $amendmentPanel['recent']->reject(fn ($a) => $a->isPending()); @endphp
            @if ($amendmentHistory->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm text-ink-soft">
                    @foreach ($amendmentHistory as $amendmentRow)
                        <li data-amendment-status="{{ $amendmentRow->status }}">
                            #{{ $amendmentRow->id }} · {{ $amendmentRow->statusLabel() }}
                            · {{ OversightFormat::clinicalTime($amendmentRow->updated_at, 'd/m/Y H:i') }} WITA
                            @if (in_array((int) $amendmentRow->id, $amendmentPanel['viewable'], true))
                                · <a class="text-brand-700 hover:underline" href="{{ route('rme.amendments.show', $amendmentRow) }}">Lihat</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @elseif ($amendmentPending === null)
                <p class="text-sm text-ink-soft">Belum ada permintaan amandemen.</p>
            @endif

            @if ($amendmentPanel['can_request'])
                <div class="mt-4">
                    <x-ui.button variant="secondary" data-amendment-request
                                 :href="route($amendmentIsChart ? 'rme.amendments.create-chart' : 'rme.amendments.create-sheet', $amendmentPanel['subject_id'])">
                        Ajukan Amandemen
                    </x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>
@endif
