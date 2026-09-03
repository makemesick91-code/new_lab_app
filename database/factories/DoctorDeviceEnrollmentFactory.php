<?php

namespace Database\Factories;

use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DoctorDeviceEnrollment>
 */
class DoctorDeviceEnrollmentFactory extends Factory
{
    protected $model = DoctorDeviceEnrollment::class;

    public function definition(): array
    {
        // A real EC P-256 keypair so factory-made rows verify like production
        // ones. Tests that need the private half generate their own.
        [$publicKey] = self::generateKeyPair();

        return [
            'uuid' => (string) Str::uuid(),
            'pairing_code_hash' => hash_hmac('sha256', Str::upper(Str::random(8)), (string) config('app.key')),
            'public_key' => $publicKey,
            'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($publicKey),
            'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
            'platform' => 'android',
            'device_model' => 'Pixel Tablet',
            'os_version' => '15',
            'app_version' => '1.0.0',
            'status' => DoctorDeviceEnrollment::STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    /**
     * @return array{0: string, 1: \OpenSSLAsymmetricKey} [base64 DER public key, private key]
     */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        $details = openssl_pkey_get_details($key);
        $pem = (string) ($details['key'] ?? '');

        // Strip the PEM armour to the base64 DER the Android client sends.
        $der = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';

        return [$der, $key];
    }
}
