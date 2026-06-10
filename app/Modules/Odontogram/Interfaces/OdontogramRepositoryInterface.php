<?php

namespace App\Modules\Odontogram\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;

interface OdontogramRepositoryInterface
{
    public function findByClinicVisit(int $clinicVisitId): ?Odontogram;

    public function createForClinicVisit(ClinicVisit $clinicVisit, array $data = []): Odontogram;

    public function updatePlaceholder(Odontogram $odontogram, array $data): Odontogram;

    public function finalize(Odontogram $odontogram, array $data): Odontogram;
}
