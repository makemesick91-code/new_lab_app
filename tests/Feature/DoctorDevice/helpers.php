<?php

/*
|--------------------------------------------------------------------------
| CI-PERF-03 — shared Android release-governance helpers for the DoctorDevice
| suites.
|--------------------------------------------------------------------------
|
| androidCheck() used to be declared in DoctorDeviceAndroidReleaseGovernanceTest
| and called from DoctorDeviceAndroidDirectApkReleaseTest. A serial run loads
| both files first, so it passed; a parallel worker that ran only the
| direct-APK suite failed both callers with "Call to undefined function" in
| every 2- and 4-worker critical run.
|
| Require this file from each test file that uses it:
|
|     require_once __DIR__.'/helpers.php';
|
| Every function is wrapped in a function_exists() guard so several test files
| may require it in the same process. The bodies are unchanged.
*/

use App\Support\Android\AndroidReleaseGovernanceScanner;

if (! function_exists('androidScanner')) {
    function androidScanner(): AndroidReleaseGovernanceScanner
    {
        return app(AndroidReleaseGovernanceScanner::class);
    }
}

if (! function_exists('androidCheck')) {
    function androidCheck(string $id): array
    {
        $checks = collect(androidScanner()->scan()['checks'])->keyBy('id');

        expect($checks->has($id))->toBeTrue("Check {$id} disappeared from the scanner.");

        return $checks->get($id);
    }
}
