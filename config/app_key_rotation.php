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

    // Code that computes hash_hmac(..., config('app.key')) — the CURRENT key
    // only. APP_PREVIOUS_KEYS keeps old ciphertext readable; it does nothing for
    // these values. Anything they issued before a rotation stops verifying the
    // moment the key changes, so each must stay short-lived and fail closed
    // (refused and re-issued, never a 500, never silently accepted).
    //
    // tests/Feature/Security/AppKeyReencryptionCurrentKeyConsumersTest.php fails
    // when another file under app/ starts reading the application key directly.
    'current_key_only_consumers' => [
        'app/Modules/DoctorDevice/Services/DoctorAppLoginService.php' => 'doctor login ticket (60 s) and challenge (120 s): an in-flight login is refused and retried',
        'app/Modules/DoctorDevice/Services/DoctorDeviceEnrollmentService.php' => 'device pairing code (15 min): an unexpired code is refused and re-issued',
        'app/Modules/RmeInvoice/Support/RmePaymentIntent.php' => 'payment form intent: a form opened before rotation is refused on submit and reopened, never paid twice',
        'app/Modules/RmeInvoice/Support/RmeRefundRequestIntent.php' => 'refund request form intent: a form opened before rotation is refused on submit (REFUND_INTENT_INVALID) and reopened; only the nonce is stored, so no filed request depends on the key and none is filed twice',
        'app/Modules/RmeInvoice/Support/RmeRefundSettlementIntent.php' => 'refund settlement start-form intent: a form opened before rotation is refused on submit (SETTLEMENT_INTENT_INVALID) and reopened; only the nonce is stored, so no recorded settlement depends on the key and none is started twice',
    ],

];
