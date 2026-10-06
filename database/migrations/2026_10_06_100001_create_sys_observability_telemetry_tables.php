<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — bounded telemetry for the
 * Observability Console.
 *
 * ADDITIVE ONLY: two new tables, nothing altered, nothing dropped, nothing
 * backfilled. Run with `migrate`, never `migrate:fresh`/`db:wipe`.
 *
 * No foreign keys, on purpose. Telemetry describes the past; it must never
 * block deleting a user, and a failed telemetry insert must never be able to
 * fail on a constraint that a clinical write would also have to satisfy.
 * user_id / branch_id are plain indexed integers.
 *
 * Every index below serves a query the console actually runs; none is
 * speculative. Retention is enforced by ObservabilityTelemetryRetention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_obs_request_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->timestamp('occurred_at');
            $table->string('request_id', 80)->nullable();

            // Actor — identifiers only. Role and branch are as observed at
            // the time of the request, not a live lookup.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_role', 64)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            // What was accessed — a route TEMPLATE, never a concrete URL
            // carrying record ids, and never a query string.
            $table->string('method', 8);
            $table->string('route_name', 160)->nullable();
            $table->string('path_template', 255);
            $table->string('activity', 160);

            // Outcome.
            $table->unsignedSmallInteger('status_code');
            $table->unsignedSmallInteger('response_status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('db_time_ms')->default(0);
            $table->unsignedInteger('query_count')->default(0);
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_misses')->default(0);
            $table->string('latency_category', 12);

            $table->boolean('is_error')->default(false);
            $table->boolean('is_slow')->default(false);
            $table->boolean('is_timeout')->default(false);

            // Sanitized exception summary (redacted, truncated, no args).
            $table->string('exception_class', 191)->nullable();
            $table->string('exception_message', 500)->nullable();
            $table->string('exception_file', 255)->nullable();
            $table->unsignedInteger('exception_line')->nullable();
            $table->json('trace_summary')->nullable();

            $table->index('occurred_at', 'sys_obs_req_occurred_idx');
            $table->index(['user_id', 'occurred_at'], 'sys_obs_req_user_occurred_idx');
            $table->index(['is_error', 'occurred_at'], 'sys_obs_req_error_occurred_idx');
            $table->index(['is_slow', 'occurred_at'], 'sys_obs_req_slow_occurred_idx');
            $table->index('request_id', 'sys_obs_req_request_id_idx');
        });

        Schema::create('sys_obs_slow_queries', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('request_id', 80)->nullable();
            $table->unsignedBigInteger('request_event_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('route_name', 160)->nullable();
            $table->string('connection', 32)->nullable();

            // sha1 of the normalized SQL — the grouping key.
            $table->char('fingerprint', 40);
            // Normalized SQL: every literal replaced by "?". Bindings are
            // never stored, so there is nothing to redact on the way out.
            $table->text('sql_normalized');
            $table->unsignedInteger('duration_ms');
            $table->string('severity', 12);

            $table->index('occurred_at', 'sys_obs_sq_occurred_idx');
            $table->index(['fingerprint', 'occurred_at'], 'sys_obs_sq_fingerprint_occurred_idx');
            $table->index('request_id', 'sys_obs_sq_request_id_idx');
            $table->index('request_event_id', 'sys_obs_sq_request_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_obs_slow_queries');
        Schema::dropIfExists('sys_obs_request_events');
    }
};
