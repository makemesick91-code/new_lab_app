<?php

/**
 * REVISION-PATIENT-KTP-LIVE-FIELD-OVERLAY-OCR-1 — prepares a SCRATCH local app
 * for live_overlay_e2e.php (development only).
 *
 * Migrates an EMPTY SQLite file, seeds permissions and roles, and creates one
 * fictional Admin Klinik operator with a selected working branch. Prints the
 * operator id so the server can be started with the pilot armed for it.
 *
 * Refuses to run unless APP_ENV=local, the connection is SQLite, and the
 * database file lies OUTSIDE the repository and is new or empty — it can never
 * touch a real database.
 *
 *   APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=/tmp/x/e2e.sqlite \
 *   E2E_EMAIL=operator@example.test E2E_PASSWORD=... php live_overlay_e2e_setup.php
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$refuse = function (string $why) {
    fwrite(STDERR, "refusing: {$why}\n");
    exit(2);
};
if (! app()->environment('local')) {
    $refuse('APP_ENV must be local');
}
if (config('database.default') !== 'sqlite') {
    $refuse('only a scratch SQLite database is supported');
}
$db = (string) config('database.connections.sqlite.database');
if ($db === '' || $db === ':memory:' || str_starts_with(realpath(dirname($db)) ?: $db, $root)) {
    $refuse('the SQLite file must be a real file outside the repository');
}
if (is_file($db) && filesize($db) > 0) {
    $refuse('the SQLite file must be new or empty');
}
$email = (string) getenv('E2E_EMAIL');
$password = (string) getenv('E2E_PASSWORD');
if ($email === '' || strlen($password) < 12) {
    $refuse('set E2E_EMAIL and an E2E_PASSWORD of at least 12 characters');
}

touch($db);
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);

$branch = Branch::factory()->create([
    'code' => 'KTPE',
    'name' => 'Cabang Uji KTP (fiktif)',
    'is_active' => true,
    'is_rme_enabled' => true,
]);
$user = User::factory()->create([
    'name' => 'Operator Uji (fiktif)',
    'email' => $email,
    'password' => Hash::make($password),
]);
$user->assignRole('Admin Klinik');
app(UserOnlineContextService::class)->startAdminClinicSession($user, (int) $branch->id);

echo json_encode(['user_id' => $user->id, 'branch_code' => $branch->code]), "\n";
