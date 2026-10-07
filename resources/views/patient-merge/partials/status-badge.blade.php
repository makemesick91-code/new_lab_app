@php($status = (string) $status)
<x-ui.badge :tone="\App\Modules\PatientMerge\Support\PatientMergeStatus::tone($status)">{{ \App\Modules\PatientMerge\Support\PatientMergeStatus::label($status) }}</x-ui.badge>
