<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — one authenticated session per
 * doctor.
 *
 * WHY A TABLE AND NOT THE EXISTING SESSION BINDING.
 *
 * A doctor's session is Laravel's `web` session plus six payload keys written
 * by DoctorDeviceSessionService::bind()
 * (app/Modules/DoctorDevice/Services/DoctorDeviceSessionService.php:163-179).
 * Every one of them lives INSIDE the session it describes, so nothing outside
 * the owning request can ask 'does this doctor already hold a session?' and
 * nothing can be marked released from outside. A claim needs a row.
 *
 * trx_user_online_contexts was rejected for the same reason its sibling
 * rejected it at database/migrations/2026_08_29_100001:11-17: it is a session
 * representation with a nullable branch, rewritten wholesale by updateOrCreate
 * on every session start, and it carries no session identity at all.
 *
 * THE INVARIANT IS AT MOST ONE UNRELEASED ROW PER USER.
 *
 * A partial unique index on `(user_id) WHERE released_at IS NULL`, created in
 * THIS migration so no later table rebuild can quietly drop the predicate.
 * Released rows are unconstrained and accumulate as the audit trail, which is
 * why the key is a nullable release stamp and not a boolean.
 *
 * Two simultaneous logins cannot both win: the loser's INSERT is refused by
 * the database, not by an application check that can be interleaved. Claim it
 * with the savepoint shape — nested DB::transaction so Laravel emits a
 * SAVEPOINT, catch the QueryException OUTSIDE it, only then read the incumbent
 * to build the denial. On PostgreSQL a failed statement aborts the whole
 * transaction, so reading the incumbent inside the failed statement's scope
 * raises 25P02 while passing on SQLite. The template is
 * DailyBranchContextService::assertSelectable()
 * (app/Modules/RmeOnlineContext/Services/DailyBranchContextService.php:147-232),
 * whose own comment records the same discovery.
 *
 * REFUSED, NOT EVICTED. The second login is denied; the first is untouched.
 * There is no idle reclaim: an incumbent is reclaimed only when its `sessions`
 * row is gone, i.e. the session is genuinely dead. A timer would be eviction
 * by the back door and would invert the requirement this table exists for.
 *
 * THERE IS NO BRANCH COLUMN HERE, AND ITS ABSENCE IS THE SCOPE OF THIS PULL
 * REQUEST. Binding a session to the branch it was established under requires
 * something that can answer what that branch IS — a home lock, an approved
 * temporary cover, and the resolver over them — and that ships separately. The
 * columns arrive with it, in their own additive migration, so that this table
 * never carries a column nothing writes: every column below has a writer in
 * this pull request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_session_leases', function (Blueprint $table) {
            $table->id();

            // THE HOLDER is the authenticated principal, users.id — the value
            // the guard and the sessions table both key on.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Audit only, and nullable on purpose. mst_doctors.user_id is
            // nullable (2026_06_29_120002:15-20), so a Doctor-role account may
            // hold no doctor record; single-session enforcement must still
            // apply to it. Recorded at claim time because the link can be
            // broken later and the lease must still say who this was.
            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Mirrors sessions.id exactly (no explicit length), so no value
            // that table accepts can ever be rejected here. Used ONLY for the
            // liveness join that distinguishes a dead session from an idle one.
            $table->string('session_id');

            // Hash only. The middleware compares this; the plaintext lives in
            // the cookie and in sessions.id, never twice.
            // Deliberately NOT unique — see the class note.
            $table->string('session_token_hash', 128);

            $table->timestamp('claimed_at');

            // Display and audit ONLY. It must never drive a reclaim: idle is
            // not dead, and a doctor who finished on the ward tablet and walked
            // to the office PC must not be locked out of their own account.
            $table->timestamp('last_seen_at')->nullable();

            // THE PARTIAL INDEX PREDICATE. Nullable stamp, not a boolean, so
            // history survives.
            $table->timestamp('released_at')->nullable();
            $table->string('released_reason', 32)->nullable();

            // Who released it, when a human did — the operator clearing a stuck
            // lease. NULL for a self-release at logout.
            $table->foreignId('released_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamps();

            $table->index('user_id');
            $table->index(
                ['user_id', 'released_at'],
                'trx_doctor_session_leases_user_released_index'
            );
            $table->index('session_token_hash');
            $table->index('session_id');
        });

        // THE CARDINALITY INVARIANT: at most one unreleased lease per user.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_session_leases_active_uq '
            .'ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_session_leases');
    }
};
