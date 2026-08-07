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
        'podman',
    ],

    // PHP major.minor the CI runtime must provide, matching the GitHub-hosted gate.
    'required_php_version' => env('CI_RUNNER_PHP_VERSION', '8.3'),

    /*
     * Authoritative CI runtime.
     *
     * The runner host is Ubuntu 26.04, which ships only PHP 8.5, while the
     * GitHub-hosted gate pins PHP 8.3. Rather than weaken the authoritative
     * requirement or reinstall the host OS, the self-hosted variant executes
     * every php/composer/artisan command inside a pinned container image.
     *
     * Engine is ROOTLESS Podman under the dedicated service user. Rootful
     * Docker and docker-group membership are forbidden: the docker group is
     * root-equivalent.
     */
    'ci_runtime' => [
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

        // Group membership that must never be granted to the service user.
        'forbidden_service_user_groups' => ['docker', 'sudo'],
    ],

    /*
     * Resource guards enforced by the health script before a heavy job runs.
     * Below these thresholds the runner reports NO-GO instead of thrashing.
     */
    'resource_guards' => [
        'min_free_disk_gb' => (int) env('CI_RUNNER_MIN_FREE_DISK_GB', 40),
        'min_available_ram_mb' => (int) env('CI_RUNNER_MIN_AVAILABLE_RAM_MB', 4096),
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
