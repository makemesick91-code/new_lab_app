<?php

/**
 * CICD-CTRL-3 — Dedicated Self-Hosted CI Runner contract.
 *
 * Read-only registry that declares the self-hosted runner safety contract so
 * App\Support\Cicd\SelfHostedRunnerScanner and
 * App\Services\Foundation\SelfHostedRunnerGovernanceService can verify the
 * Foundation Evidence Gates workflow, the runner health script, and the
 * production-isolation guard honour it — without mutating anything.
 *
 * SAFETY CONTRACT:
 *  - GitHub Actions stays the authoritative CI control plane. A self-hosted
 *    runner adds execution capacity; it never becomes a second source of truth
 *    and never makes CI independent of a GitHub Actions outage.
 *  - The production VPS is NEVER a general CI runner. Deployment stays on its
 *    own boundary (scripts/deploy-vps-runner.sh, executed ON the VPS).
 *  - The runner holds no production environment file, no production database
 *    credential, and no production SSH private key.
 *  - Heavy jobs opt in by an explicit label set; they never inherit a bare
 *    `self-hosted` label that any other runner could satisfy.
 *  - Runner outage must QUEUE a required job, never let it silently pass.
 *  - Falling back to GitHub-hosted is an explicit operator action and must run
 *    an equivalent gate, never a weaker one.
 */
return [
    'sprint' => 'CICD-CTRL-3',

    // Master switch for the CICD-CTRL-3 governance surface.
    'enabled' => (bool) env('CI_RUNNER_GOVERNANCE_ENABLED', true),

    // Files the governance layer inspects. Missing any is a hard FAIL.
    'files' => [
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'deploy_workflow' => '.github/workflows/deploy-vps.yml',
        'runner_health_script' => 'scripts/ci/self-hosted-runner-health.sh',
        'classifier_script' => 'scripts/ci/resolve-gates.sh',
        'ci_runtime_wrapper' => 'scripts/ci/self-hosted-php.sh',
    ],

    // Identity of the dedicated runner. Registration uses exactly this name so
    // an unrelated runner can never silently pick up DaengtisiaMS heavy jobs.
    'runner' => [
        'name' => env('CI_RUNNER_NAME', 'daengtisia-ci-01'),
        'service_user' => env('CI_RUNNER_SERVICE_USER', 'github-runner'),
        'work_folder' => '_work',
    ],

    /*
     * The label set a heavy self-hosted job must target. `daengtisia-ci` is the
     * custom label that makes the target unambiguous — a bare `self-hosted`
     * runs-on is forbidden (see `forbidden_workflow_markers`).
     */
    'required_labels' => [
        'self-hosted',
        'linux',
        'x64',
        'daengtisia-ci',
    ],

    // The custom label that uniquely identifies this project's runner.
    'custom_label' => 'daengtisia-ci',

    /*
     * Jobs that MUST remain on GitHub-hosted runners. The classifier decides
     * which gates run; it must itself always run on neutral infrastructure so a
     * dead self-hosted runner can never stop the routing decision from being
     * made. Deployment never runs on a general CI runner.
     */
    'always_github_hosted_jobs' => [
        'classify',
        'deploy',
    ],

    /*
     * Heavy jobs approved for self-hosted execution. Migration is incremental
     * (CICD-CTRL-3 starts with the critical regression gate); adding an entry
     * here requires extending the tests and re-running the governance check.
     */
    'self_hosted_heavy_jobs' => [
        'critical_test_gate_self_hosted',
    ],

    /*
     * Runner mode resolution. `github-hosted` is the fail-safe default: if the
     * repository variable is unset, or the runner is decommissioned, CI keeps
     * running on GitHub-hosted infrastructure with no code change.
     */
    'runner_mode' => [
        'default' => 'github-hosted',
        'allowed' => ['github-hosted', 'self-hosted'],
        'repository_variable' => 'CI_RUNNER_MODE',
        'dispatch_input' => 'runner_mode',
    ],

    /*
     * Markers that MUST appear in the workflow for the self-hosted routing to
     * be considered safe.
     */
    'required_workflow_markers' => [
        'daengtisia-ci',
        'runner_mode',
        'ci:assert-non-production-database',
        // The self-hosted variant must execute through the pinned CI runtime,
        // never against the host PHP.
        'scripts/ci/self-hosted-php.sh',
        'REQUIRED_PHP',
    ],

    /*
     * Markers that MUST NEVER appear in the workflow.
     *
     * `runs-on: self-hosted` (bare, unlabelled) would let any self-hosted
     * runner on the account pick up the job. `paths-ignore` is inherited from
     * the CICD-CTRL-1 contract and stays forbidden.
     */
    'forbidden_workflow_markers' => [
        'runs-on: self-hosted',
        'paths-ignore',
    ],

    /*
     * Steps whose exit status MUST survive a pipe.
     *
     * A shell pipeline reports the status of its LAST command, so
     * `producer | tee evidence` reports tee's success and discards the
     * producer's failure. The runner health check was written that way: it
     * printed `DECISION: NO-GO`, exited 1, and the step still went green —
     * migrations and Pest then ran on a runner that had just failed its own
     * safety check.
     *
     * Every step named here writes evidence through a pipe AND gates something
     * safety-critical, so each must either declare `shell: bash` (which adds
     * `-o pipefail`) or capture PIPESTATUS explicitly. Evidence is never
     * dropped to work around this — it is preserved and the status propagated.
     *
     * CICD-CTRL-3B extends the list to the NSF-9 and NSF-10 producers. That gap
     * is why the defect survived CICD-CTRL-3A: the check below was already
     * correct, but it only ran against the steps named here, and the release
     * gates were not among them. Every one of these producers exits non-zero
     * for exactly one reason — the governance decision is FAIL (GO and WATCH
     * both exit 0) — so a swallowed status turned a release-blocking NO-GO into
     * a green required check.
     */
    'strict_pipeline_steps' => [
        // CICD-CTRL-3A — self-hosted runner and critical regression gate.
        'Runner health check (CICD-CTRL-3)',
        'Assert authoritative PHP runtime (CICD-CTRL-3)',
        'Self-hosted runner smoke evidence (CICD-CTRL-3)',
        'Run critical regression tests',

        // CICD-CTRL-3B — NSF-9 release safety & automated smoke gate.
        'Foundation roadmap check',
        'Feature flags governance',
        'Release safety check',
        'Automated smoke (command-readiness only, no base URL in CI)',

        // CICD-CTRL-3B — NSF-10 release evidence gate.
        'Capture NSF-10 release evidence (ci profile)',
        'Check NSF-10 release evidence (ci profile)',
        'Release safety check (ci profile)',
    ],

    /*
     * Runtime evidence must describe what actually executed.
     *
     * The self-hosted evidence used to name a container engine unconditionally,
     * so a run on a host with no Podman — executing the native runtime — still
     * reported "engine=rootless podman" while its own smoke line read
     * `container_engine=` (empty). Evidence that contradicts itself is worse
     * than no evidence, because it gets quoted in closure reports.
     */
    'runtime_evidence' => [
        // The wrapper resolves and reports the mode; the workflow must ask it
        // rather than assert a mode of its own.
        'required_marker' => '--print-runtime',

        // Claims that assert one specific engine unconditionally.
        'forbidden_markers' => [
            'engine=rootless podman',
            'container_engine=$(podman --version)',
        ],
    ],

    /*
     * Production commands that must never appear in the CI workflow. Deployment
     * and rollback belong to the VPS boundary, not to a CI runner.
     */
    'forbidden_ci_production_commands' => [
        'deploy-vps-runner.sh',
        'deploy-vps.sh',
        'rollback-vps.sh',
    ],

    /*
     * Production database isolation guard (`ci:assert-non-production-database`).
     *
     * The guard is rule-based, not IP-based: a CI database must be local and
     * must carry a CI/test name. That blocks every remote database, including
     * ones nobody thought to denylist.
     */
    'database_guard' => [
        // APP_ENV values a CI run may use.
        'allowed_app_envs' => ['testing'],

        // Hosts a CI database may live on. Anything else is a hard FAIL.
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_ALLOWED_HOSTS', '127.0.0.1,localhost,::1,postgres'))
        ))),

        // Database names a CI run may use, exact match.
        'allowed_databases' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_ALLOWED_DATABASES', 'testing,daengtisia_ci'))
        ))),

        // Additional accepted name shapes for disposable CI databases.
        'allowed_database_patterns' => [
            '/^daengtisia_ci(_[a-z0-9_]+)?$/',
            '/^testing(_[a-z0-9_]+)?$/',
            '/_(test|testing|ci)$/',
        ],

        /*
         * Known production database names. Explicit denial on top of the
         * allowlist so a misconfigured allowlist still cannot reach production.
         */
        'denied_databases' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CI_DB_DENIED_DATABASES', 'asia_dental_lab_pilot,asia_dental_lab'))
        ))),

        // Substrings that mark a database name as production-like.
        'denied_database_substrings' => ['pilot', 'prod', 'production', 'live'],
    ],

    /*
     * Paths that must NOT exist on the runner (checked by the health script).
     * The runner is a CI machine; production material has no reason to be there.
     */
    'forbidden_runner_paths' => [
        'production_ssh_key' => '.ssh/daengtisiams_vps_ed25519',
        'production_deploy_key' => '.ssh/daengtisiams_deploy',
    ],

    /*
     * Host tooling the runner must provide. The runner is pre-provisioned so CI
     * jobs never need sudo at run time.
     *
     * PHP, Composer and Poppler are deliberately NOT host requirements — they
     * come from the pinned CI image (see `ci_runtime`), because the host OS
     * cannot supply the authoritative PHP version.
     */
    'required_host_tooling' => [
        'node',
        'npm',
        'psql',
        'git',
    ],

    /*
     * Tooling required in addition to the above, per runtime mode.
     *
     * In native mode the host itself is the authoritative runtime, so PHP,
     * Composer and Poppler become host requirements. In podman mode they are
     * supplied by the pinned image instead and must NOT be demanded of the host.
     */
    'required_host_tooling_by_mode' => [
        'native' => ['php', 'composer', 'pdfinfo', 'pdftoppm'],
        'podman' => ['podman'],
    ],

    // PHP major.minor the CI runtime must provide, matching the GitHub-hosted gate.
    'required_php_version' => env('CI_RUNNER_PHP_VERSION', '8.3'),

    /*
     * Authoritative CI runtime.
     *
     * The contract is SEMANTIC RUNTIME EQUIVALENCE, not a specific container
     * engine: whatever executes a CI command must provide the same PHP
     * major.minor, the same extension set and the same Poppler binaries as the
     * GitHub-hosted gate. The wrapper proves that at run time in either mode and
     * never falls back to a mismatched PHP.
     *
     * native — the host OS ships the authoritative PHP (Ubuntu 24.04 ships 8.3).
     *          Preferred: no image build, no rootless plumbing, no userns
     *          mapping, fewer moving parts to drift.
     * podman — the runtime comes from the pinned image. Required when the host
     *          cannot supply the authoritative PHP; this is why the wrapper was
     *          originally written, on an Ubuntu 26.04 host that shipped only
     *          PHP 8.5. Runs ROOTLESS under the dedicated service user.
     *
     * Docker is forbidden in BOTH modes: the docker group is root-equivalent.
     */
    'ci_runtime' => [
        'mode' => env('CI_RUNTIME_MODE', 'auto'),
        'allowed_modes' => ['native', 'podman'],

        // Container engine used when (and only when) mode resolves to podman.
        'engine' => 'podman',
        'rootless' => true,
        'image' => env('CI_PHP_IMAGE', 'localhost/daengtisia-ci-php:8.3'),
        'containerfile' => '.github/ci-runtime/Containerfile.php83',
        'wrapper_script' => 'scripts/ci/self-hosted-php.sh',

        // The base image must be pinned by digest, never by a floating tag.
        'require_digest_pin' => true,

        // Extension set the image must provide, mirroring the setup-php list
        // used by the GitHub-hosted critical gate.
        'required_extensions' => [
            'dom',
            'curl',
            'libxml',
            'mbstring',
            'zip',
            'pcntl',
            'pdo',
            'pdo_pgsql',
            'bcmath',
            'gd',
            'exif',
        ],

        /*
         * Poppler must exist inside the image: the critical filter covers
         * LegacyRme, whose Poppler suite SKIPS when these are missing. Omitting
         * them would silently make the self-hosted gate weaker than the
         * authoritative one.
         */
        'required_binaries' => ['pdfinfo', 'pdftoppm'],

        /*
         * Group membership that must never be granted to the service user.
         *
         * These are checked unconditionally by the health script — never behind
         * a container-runtime probe, because a missing tool must not be able to
         * suppress a root-equivalence finding.
         *
         * docker / lxd  : both hand out root-equivalent control of the host.
         * sudo / wheel  : direct privilege escalation.
         * root          : membership in the root group.
         * adm / disk / shadow : privileged read of logs, raw devices, and the
         *                 password database respectively.
         */
        'forbidden_service_user_groups' => [
            'docker',
            'sudo',
            'lxd',
            'wheel',
            'root',
            'adm',
            'disk',
            'shadow',
        ],
    ],

    /*
     * CI database parity.
     *
     * The authoritative gate runs `postgres:16` as a service container. The
     * runner host ships PostgreSQL 18, and that version difference produced
     * self-hosted-only test failures (aborted-transaction cascades out of
     * Dq1AuditService) — so the CI database is pinned to the same major version
     * the gate uses, supplied the same way the PHP runtime is: a pinned image
     * under rootless Podman, loopback-only.
     *
     * The host PostgreSQL is deliberately left untouched; a co-tenant project
     * on this machine may depend on it.
     */
    'ci_database' => [
        'engine' => 'podman',
        'rootless' => true,
        'image' => env('CI_DB_IMAGE', 'docker.io/library/postgres:16'),
        // Resolved 2026-08-07; PostgreSQL 16.14, the same major production runs.
        'image_digest' => 'sha256:670391653713782e51974845b217c56fed4dd8729142299c43c919a8d3e15e00',
        'service_unit' => 'ci-pg16.service',
        'host' => env('CI_RUNNER_DB_HOST', '127.0.0.1'),
        'port' => (int) env('CI_RUNNER_DB_PORT', 5433),
        'database' => env('CI_RUNNER_DB_NAME', 'daengtisia_ci'),
        'username' => env('CI_RUNNER_DB_USER', 'daengtisia_ci_user'),

        // Must match the major version of the workflow's service container.
        'required_major_version' => env('CI_RUNNER_DB_MAJOR', '16'),

        // Pinned by digest for the same reason as the PHP runtime.
        'require_digest_pin' => true,
    ],

    /*
     * Resource guards enforced by the health script before a heavy job runs.
     * Below these thresholds the runner reports NO-GO instead of thrashing.
     */
    'resource_guards' => [
        /*
         * Sized for the actual CI host, not for a hypothetical large one.
         *
         * The previous 40GB floor was set against a 231GB laptop disk. On the
         * 58GB Biznet VPS it would report NO-GO permanently — a guard that can
         * never pass is not a guard, it is an outage. 15GB still leaves room for
         * a full vendor/ + node_modules/ + workspace + database on this host,
         * and the bounded-cache policy below keeps it from creeping.
         *
         * RAM: 2GB available is the floor for one sequential heavy job on a
         * 7.8GB host with 4GB of swap behind it.
         */
        'min_free_disk_gb' => (int) env('CI_RUNNER_MIN_FREE_DISK_GB', 15),
        'min_available_ram_mb' => (int) env('CI_RUNNER_MIN_AVAILABLE_RAM_MB', 2048),
    ],

    /*
     * Concurrency posture. Heavy jobs run one at a time on this hardware; the
     * Pest worker count is set from a real benchmark, never from core count.
     */
    'concurrency' => [
        'max_parallel_heavy_jobs' => (int) env('CI_RUNNER_MAX_HEAVY_JOBS', 1),
        'pest_workers' => (int) env('CI_RUNNER_PEST_WORKERS', 2),
        'pest_workers_benchmarked' => [1, 2, 3],
    ],

    // The CICD-CTRL-3 governance rule ids (published into the foundation summary).
    'governance_section' => 'self_hosted_runner_governance',
];
