<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Interfaces\PatientDuplicateCandidateRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Support\PatientIdentityMask;
use App\Modules\PatientMerge\Support\PatientMergeField;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — POSSIBLE duplicates, never
 * conclusions.
 *
 * The score ranks pairs for a human to look at. It is not identity authority
 * and nothing here merges anything: there is no code path from a score to a
 * merge. A match needs a similar name AND a shared birth date or phone; a
 * shared birth date alone (twins, common dates) is never enough.
 *
 * Bounded by construction: pairs are formed only within blocks of patients
 * sharing an indexed key, with caps on blocks and block size, so detection
 * cost does not grow with the square of the patient table.
 */
class PatientDuplicateDetectionService
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_LABELS = [
        self::CONFIDENCE_HIGH => 'Tinggi',
        self::CONFIDENCE_MEDIUM => 'Sedang',
        self::CONFIDENCE_LOW => 'Rendah',
    ];

    /** Blocks examined per key. */
    public const MAX_BLOCKS = 400;

    /** Patients examined per block; a larger block (a placeholder date) is skipped. */
    public const MAX_BLOCK_SIZE = 25;

    /** Pairs kept in one computation. */
    public const MAX_PAIRS = 1000;

    public const NAME_SIMILAR = 0.75;

    public const NAME_STRONG = 0.9;

    public function __construct(
        private readonly PatientDuplicateCandidateRepositoryInterface $candidates,
        private readonly PatientMergeCaseRepositoryInterface $cases,
        private readonly PatientMergeScope $scope,
        private readonly BranchService $branches,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  confidence, signal, branch_id
     */
    public function paginate(?User $user, array $filters, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $pairs = $this->pairsFor($this->filteredBranchIds($user, $filters));

        if (! empty($filters['confidence'])) {
            $pairs = $pairs->where('confidence', $filters['confidence']);
        }

        if (! empty($filters['signal'])) {
            $pairs = $pairs->filter(fn (array $pair): bool => ($pair['signals'][$filters['signal']] ?? null) === 'match');
        }

        $pairs = $pairs->values();
        $page = max(1, $page);

        return new LengthAwarePaginator(
            $pairs->forPage($page, $perPage)->values(),
            $pairs->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function countFor(?User $user): int
    {
        return $this->pairsFor($this->scope->branchIdsFor($user))->count();
    }

    /**
     * Ranked candidate pairs inside a branch scope.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    public function pairsFor(array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        $pairs = [];

        foreach (['date_of_birth', 'phone', 'whatsapp_number'] as $column) {
            $values = $this->candidates->sharedValues($branchIds, $column, self::MAX_BLOCKS);

            // One query per key for every block, grouped here by the RAW
            // stored value — never one query per block.
            $blocks = $this->candidates
                ->patientsWithValues($branchIds, $column, $values, self::MAX_BLOCKS * (self::MAX_BLOCK_SIZE + 1))
                ->groupBy(fn (Patient $patient): string => (string) $patient->getRawOriginal($column));

            foreach ($blocks as $members) {
                if ($members->count() > self::MAX_BLOCK_SIZE) {
                    continue;
                }

                $list = $members->values();

                for ($i = 0; $i < $list->count(); $i++) {
                    for ($j = $i + 1; $j < $list->count(); $j++) {
                        $key = min($list[$i]->id, $list[$j]->id).'-'.max($list[$i]->id, $list[$j]->id);

                        if (isset($pairs[$key])) {
                            continue;
                        }

                        $scored = $this->scorePair($list[$i], $list[$j]);

                        if ($scored !== null) {
                            $pairs[$key] = $scored;
                        }

                        if (count($pairs) >= self::MAX_PAIRS) {
                            break 4;
                        }
                    }
                }
            }
        }

        return $this->attachOpenCases(collect($pairs))
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Score a pair, or null when it is not a candidate.
     *
     * @return array<string, mixed>|null
     */
    public function scorePair(Patient $a, Patient $b): ?array
    {
        if ($a->id === $b->id) {
            return null;
        }

        [$a, $b] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        $nameA = PatientMergeField::normalize('name', $a->name);
        $nameB = PatientMergeField::normalize('name', $b->name);
        $nameSimilarity = $nameA !== null && $nameB !== null ? PatientIdentityComparator::similarity($nameA, $nameB) : 0.0;

        $signals = [
            'name' => $nameSimilarity >= self::NAME_STRONG ? 'match' : ($nameSimilarity >= self::NAME_SIMILAR ? 'similar' : 'conflict'),
            'date_of_birth' => $this->signal('date_of_birth', $a->date_of_birth, $b->date_of_birth),
            'phone' => $this->phoneSignal($a, $b),
            'gender' => $this->signal('gender', $a->gender, $b->gender),
            'ktp_number' => $this->signal('ktp_number', $a->ktp_number, $b->ktp_number),
        ];

        $sharedKey = $signals['date_of_birth'] === 'match' || $signals['phone'] === 'match';

        // A similar name and a shared key; or a shared birth date AND phone
        // (a nickname / spelling difference). Anything less is not shown.
        $isCandidate = ($nameSimilarity >= self::NAME_SIMILAR && $sharedKey)
            || ($signals['date_of_birth'] === 'match' && $signals['phone'] === 'match');

        if (! $isCandidate) {
            return null;
        }

        $score = (int) round($nameSimilarity * 50)
            + ($signals['date_of_birth'] === 'match' ? 25 : 0)
            + ($signals['phone'] === 'match' ? 20 : 0)
            + ($signals['gender'] === 'match' ? 5 : 0)
            - ($signals['ktp_number'] === 'conflict' ? 15 : 0)
            - ($signals['date_of_birth'] === 'conflict' ? 20 : 0)
            - ($signals['gender'] === 'conflict' ? 10 : 0);

        $confidence = match (true) {
            $score >= 85 => self::CONFIDENCE_HIGH,
            $score >= 65 => self::CONFIDENCE_MEDIUM,
            default => self::CONFIDENCE_LOW,
        };

        $riskFlags = array_values(array_filter([
            $signals['ktp_number'] === 'conflict' ? 'ktp_conflict' : null,
            $signals['date_of_birth'] === 'conflict' ? 'dob_conflict' : null,
            $signals['gender'] === 'conflict' ? 'gender_conflict' : null,
            $this->scope->isCrossBranch($a, $b) ? 'cross_branch' : null,
        ]));

        return [
            'key' => $a->id.'-'.$b->id,
            'a' => $this->summary($a),
            'b' => $this->summary($b),
            'name_similarity' => $nameSimilarity,
            'signals' => $signals,
            'score' => max(0, min(100, $score)),
            'confidence' => $confidence,
            'risk_flags' => $riskFlags,
            'open_case_uuid' => null,
        ];
    }

    /**
     * Existing patients a NEW registration most likely duplicates. Same scope
     * as the global New Visit identity lookup (active RME branches + legacy),
     * and the same least-disclosure summary.
     *
     * @param  array<string, mixed>  $input  name, date_of_birth, phone, whatsapp_number
     * @return array<int, array<string, mixed>>
     */
    public function registrationMatches(array $input, ?User $actor = null): array
    {
        $name = PatientMergeField::normalize('name', $input['name'] ?? null);

        if ($name === null) {
            return [];
        }

        $birthDate = PatientMergeField::normalize('date_of_birth', $input['date_of_birth'] ?? null);
        $phones = [];

        foreach (['phone', 'whatsapp_number'] as $field) {
            $digits = PatientMergeField::digits((string) ($input[$field] ?? ''));

            if ($digits !== null) {
                $phones = array_merge($phones, [$digits, '62'.ltrim($digits, '0'), '+62'.ltrim($digits, '0')]);
            }
        }

        $found = $this->candidates->registrationCandidates(
            $this->branches->rmeEnabledIds(),
            $birthDate,
            $phones,
            50,
        );

        $matches = [];

        foreach ($found as $patient) {
            $existing = PatientMergeField::normalize('name', $patient->name);
            $similarity = $existing === null ? 0.0 : PatientIdentityComparator::similarity($name, $existing);
            $sameBirth = $birthDate !== null && PatientMergeField::normalize('date_of_birth', $patient->date_of_birth) === $birthDate;
            $samePhone = $phones !== [] && (
                in_array(PatientMergeField::digits((string) $patient->phone), $phones, true)
                || in_array(PatientMergeField::digits((string) $patient->whatsapp_number), $phones, true)
            );

            // Strong only: a same-day birth needs a near-identical name, so two
            // patients who merely share a birthday are never stopped.
            if (($sameBirth && $similarity >= 0.85) || ($samePhone && $similarity >= self::NAME_SIMILAR)) {
                // Identity lookup is global (rule 101), but only the minimum
                // registration identity leaves the actor's branch scope: a
                // patient elsewhere shows name, Nomor RM and branch — never its
                // birth date, phone or NIK fragment.
                $inScope = $actor !== null && $this->scope->covers($actor, $patient);
                $row = $inScope ? $this->summary($patient) : $this->minimalSummary($patient);
                $matches[] = $row + ['in_scope' => $inScope, 'name_similarity' => $similarity];
            }
        }

        usort($matches, fn (array $x, array $y): int => $y['name_similarity'] <=> $x['name_similarity']);

        return array_slice($matches, 0, 5);
    }

    /**
     * Least-disclosure summary of a patient for candidate lists.
     *
     * @return array<string, mixed>
     */
    public function summary(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'medical_record_number' => $patient->medical_record_number,
            'branch_label' => $patient->branchLabel(),
            'date_of_birth' => PatientIdentityMask::field('date_of_birth', $patient->date_of_birth),
            'phone_masked' => PatientIdentityMask::phone($patient->phone ?: $patient->whatsapp_number),
            'ktp_masked' => PatientIdentityMask::ktp($patient->ktp_number),
        ];
    }

    /** @return array<string, mixed> the New Visit identity-lookup minimum */
    private function minimalSummary(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'medical_record_number' => $patient->medical_record_number,
            'branch_label' => $patient->branchLabel(),
        ];
    }

    private function signal(string $field, mixed $a, mixed $b): string
    {
        $x = PatientMergeField::normalize($field, $a);
        $y = PatientMergeField::normalize($field, $b);

        return match (true) {
            $x === null || $y === null => 'missing',
            $x === $y => 'match',
            default => 'conflict',
        };
    }

    private function phoneSignal(Patient $a, Patient $b): string
    {
        $aNumbers = array_filter([PatientMergeField::digits((string) $a->phone), PatientMergeField::digits((string) $a->whatsapp_number)]);
        $bNumbers = array_filter([PatientMergeField::digits((string) $b->phone), PatientMergeField::digits((string) $b->whatsapp_number)]);

        if ($aNumbers === [] || $bNumbers === []) {
            return 'missing';
        }

        return array_intersect($aNumbers, $bNumbers) !== [] ? 'match' : 'conflict';
    }

    /** @return array<int, int> */
    private function filteredBranchIds(?User $user, array $filters): array
    {
        $scope = $this->scope->branchIdsFor($user);
        $requested = isset($filters['branch_id']) ? (int) $filters['branch_id'] : 0;

        // A requested branch may only NARROW, never widen.
        return $requested > 0 && in_array($requested, $scope, true) ? [$requested] : $scope;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $pairs
     * @return Collection<string, array<string, mixed>>
     */
    private function attachOpenCases(Collection $pairs): Collection
    {
        // One query for every open case, not one per pair.
        $open = $pairs->isEmpty() ? [] : $this->cases->openPairKeys();

        return $pairs->map(function (array $pair) use ($open): array {
            $pair['open_case_uuid'] = $open[$pair['key']] ?? null;

            return $pair;
        });
    }
}
