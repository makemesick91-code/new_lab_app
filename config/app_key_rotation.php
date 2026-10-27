<?php

/*
|--------------------------------------------------------------------------
| SECURITY-G7B-APP-KEY-REENCRYPTION-1 — application key rotation
|--------------------------------------------------------------------------
|
| Every database column the application encrypts with the application key.
| Laravel decrypts a value with the current key and then each key listed in
| APP_PREVIOUS_KEYS, so a rotated key keeps old rows readable — but only while
| the old key stays configured. `security:app-key-reencrypt` rewrites each value
| still encrypted under a previous key with the current key, so the previous key
| can be retired.
|
| tests/Feature/Security/AppKeyReencryptionTest.php fails when a model declares
| an `encrypted` cast that is not listed here, so a new encrypted column cannot
| silently keep a retired key alive.
|
*/

return [

    'encrypted_columns' => [
        ['table' => 'trx_satusehat_candidates', 'key' => 'id', 'column' => 'eligibility_snapshot'],
        ['table' => 'trx_satusehat_candidates', 'key' => 'id', 'column' => 'preview_snapshot'],
        ['table' => 'trx_patient_merge_cases', 'key' => 'id', 'column' => 'identity_snapshot_encrypted'],
        ['table' => 'trx_patient_merge_field_resolutions', 'key' => 'id', 'column' => 'final_value_encrypted'],
    ],

    // Rows read per query while inspecting. Writes are always one row per
    // transaction, under a row lock.
    'chunk_size' => 200,

];
