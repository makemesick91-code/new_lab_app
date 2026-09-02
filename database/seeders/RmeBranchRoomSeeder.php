<?php

namespace Database\Seeders;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * REVISION-SUNU-ADD-ROOM-A-B-1 — canonical room registry for a named RME branch.
 *
 * WHY THIS IS NOT ClinicRoomSeeder. ClinicRoomSeeder provisions the DEFAULT room
 * set for whichever branch BranchContext currently resolves to — in practice
 * MAIN. It cannot express "these rooms belong to Cabang Sunu", and its
 * `updateOrCreate` keyed on (branch_id, code) queries only non-trashed rows
 * while the unique indexes it collides with do not. The two seeders answer
 * different questions and both stay.
 *
 * WHY SOFT DELETES ARE THE WHOLE PROBLEM. `mst_clinic_rooms` carries
 * UNIQUE(branch_id, code) and UNIQUE(branch_id, name), and NEITHER is
 * conditioned on `deleted_at`. A soft-deleted "Ruangan A" therefore still
 * occupies both slots. Inserting over it does not produce a duplicate row — it
 * raises a constraint violation, and in a deploy that is an aborted release.
 * Every lookup below is deliberately `withTrashed()`.
 *
 * WHY THE NAME IS THE IDENTITY. The product decision names two rooms, "Ruangan
 * A" and "Ruangan B". The `code` is an identifier this registry ASSIGNS when it
 * creates a room; it is not what makes the room that room. So a room already
 * carrying the right name under an operator-chosen code is ADOPTED as-is and
 * its code is left alone — master data stays owned by Master Data Ruangan.
 *
 * WHERE IT REFUSES. Both unique indexes guarantee at most one match each, so
 * the only genuinely ambiguous shapes are: the declared code held by a
 * DIFFERENTLY named room, or the code and the name resolving to two DIFFERENT
 * rows. Either would force this seeder to invent a code or to pick a winner
 * between two operator-created rooms. It refuses instead. Guessing here is how
 * one clinic ends up with two "Ruangan A"s, or with a room silently renamed
 * underneath the queue that references it.
 *
 * WHAT IT NEVER DOES. It never deletes, never renames, never reassigns a visit,
 * never touches a branch other than the ones declared below, and never creates
 * a branch — Cabang Sunu is RmeBranchSeeder's to own, and DatabaseSeeder runs
 * that first.
 *
 * Safe to run repeatedly: `php artisan db:seed --class=RmeBranchRoomSeeder --force`.
 */
class RmeBranchRoomSeeder extends Seeder
{
    /**
     * Canonical per-branch room registry, keyed by CANONICAL branch code.
     *
     * Cabang Sunu only. The sibling RME branches already carry operator-created
     * rooms whose codes were issued under their own history (Telkomas' rooms are
     * still `TKM-*`, from the branch code it held before TLK1). Re-declaring
     * those here could only rename production master data or duplicate it, so
     * they are deliberately absent: adding a branch to this registry is a
     * decision, never housekeeping.
     *
     * The codes below follow the convention the existing rooms already
     * established — the branch's three-letter prefix, then the room letter
     * (LDK-A, ATG-A, TKM-A) — read off the CANONICAL code SPN4, never the
     * deprecated SUN4.
     *
     * @var array<string, array<int, array{code: string, name: string, type: string}>>
     */
    public const CANONICAL_BRANCH_ROOMS = [
        BranchCodeAlias::SUNU_CANONICAL => [
            ['code' => 'SPN-A', 'name' => 'Ruangan A', 'type' => ClinicRoom::TYPE_TREATMENT_ROOM],
            ['code' => 'SPN-B', 'name' => 'Ruangan B', 'type' => ClinicRoom::TYPE_TREATMENT_ROOM],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::CANONICAL_BRANCH_ROOMS as $branchCode => $rooms) {
                $branch = $this->resolveBranch($branchCode);

                foreach ($rooms as $room) {
                    $this->converge((int) $branch->id, $room);
                }
            }
        });
    }

    /**
     * Resolve the branch by EVERY code that names it — canonical and deprecated
     * alike — so a deployment whose branch row has not been migrated yet is
     * recognised rather than treated as missing.
     */
    private function resolveBranch(string $branchCode): Branch
    {
        $branch = Branch::withTrashed()
            ->whereIn('code', BranchCodeAlias::equivalentCodes($branchCode))
            ->first();

        if ($branch === null) {
            throw new RuntimeException(
                "RmeBranchRoomSeeder: branch {$branchCode} was not found. Run RmeBranchSeeder first; "
                .'this seeder provisions rooms and never creates a branch.'
            );
        }

        return $branch;
    }

    /**
     * @param  array{code: string, name: string, type: string}  $room
     */
    private function converge(int $branchId, array $room): void
    {
        $byName = ClinicRoom::withTrashed()
            ->where('branch_id', $branchId)
            ->where('name', $room['name'])
            ->first();

        $byCode = ClinicRoom::withTrashed()
            ->where('branch_id', $branchId)
            ->where('code', $room['code'])
            ->first();

        // The declared code belongs to some other room. Creating our room would
        // need a code this registry does not declare; adopting that row would
        // rename an operator's room. Neither is ours to decide.
        if ($byCode !== null && ($byName === null || (int) $byCode->id !== (int) $byName->id)) {
            throw new RuntimeException(sprintf(
                'RmeBranchRoomSeeder: cannot provision "%s" (%s) for branch %d — code %s is already held by room #%d ("%s"). '
                .'Resolve this in Master Data Ruangan; provisioning will not choose between two rooms.',
                $room['name'], $room['code'], $branchId, $room['code'], $byCode->id, $byCode->name
            ));
        }

        if ($byName === null) {
            ClinicRoom::query()->create([
                'branch_id' => $branchId,
                'code' => $room['code'],
                'name' => $room['name'],
                'type' => $room['type'],
                'status' => ClinicRoom::STATUS_ACTIVE,
                'description' => null,
            ]);

            return;
        }

        // The room already exists. Converge only what this sprint decided —
        // that it is present and usable. Its code, type and description stay
        // exactly as Master Data Ruangan left them.
        if ($byName->trashed()) {
            $byName->restore();
        }

        if ($byName->status !== ClinicRoom::STATUS_ACTIVE) {
            $byName->forceFill(['status' => ClinicRoom::STATUS_ACTIVE])->save();
        }
    }
}
