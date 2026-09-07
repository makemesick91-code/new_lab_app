<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — a real software authenticator, for tests.
 *
 * WHY THIS IS NOT A MOCK
 *
 * A mocked verifier proves that the code calls a function. It does not prove
 * that a forged signature is refused, that a replayed challenge fails, that a
 * credential from a different key pair is rejected, or that the relying party
 * id is actually bound into what was signed. Those are the properties the whole
 * sprint rests on, and every one of them is invisible to a mock.
 *
 * So this generates a genuine EC P-256 key pair, builds real authenticator data
 * and real client data, and signs them with OpenSSL. The library then verifies
 * them with no knowledge that they came from a test. A test that passes here
 * has exercised the actual cryptography.
 *
 * It is also, deliberately, an ATTACKER's toolkit: it can sign the wrong
 * challenge, claim the wrong origin, forge a signature with a second key, or
 * clear the user-verified flag. Those are the negative tests that matter.
 */
final class FakeWebAuthnAuthenticator
{
    private \OpenSSLAsymmetricKey $privateKey;

    private string $credentialId;

    private int $signCount = 0;

    public function __construct(
        private readonly string $rpId,
        private readonly string $origin,
        private readonly bool $backupEligible = false,
        private readonly bool $backupState = false,
    ) {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('Unable to create a test EC key.');
        }

        $this->privateKey = $key;
        $this->credentialId = random_bytes(32);
    }

    public function credentialIdBase64Url(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    /**
     * A `navigator.credentials.create()` result, as the browser would post it.
     *
     * @return array<string, mixed>
     */
    public function attestation(
        string $challengeBase64Url,
        ?string $originOverride = null,
        bool $userVerified = true,
    ): array {
        $clientDataJSON = $this->clientData('webauthn.create', $challengeBase64Url, $originOverride);

        $authData = $this->authenticatorData($userVerified, includeAttestedCredentialData: true);

        // CBORObject stringifies to its own encoding, so no Encoder is needed.
        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => $this->credentialIdBase64Url(),
            'rawId' => $this->credentialIdBase64Url(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
            ],
        ];
    }

    /**
     * A `navigator.credentials.get()` result.
     *
     * @param  bool  $forgeSignature  sign with a DIFFERENT key pair, to prove a
     *                                forged signature is refused
     * @return array<string, mixed>
     */
    public function assertion(
        string $challengeBase64Url,
        string $userHandle,
        ?string $originOverride = null,
        bool $userVerified = true,
        bool $forgeSignature = false,
    ): array {
        $clientDataJSON = $this->clientData('webauthn.get', $challengeBase64Url, $originOverride);

        $this->signCount++;

        $authData = $this->authenticatorData($userVerified, includeAttestedCredentialData: false);

        $signedBytes = $authData.hash('sha256', $clientDataJSON, true);

        $signature = '';

        if ($forgeSignature) {
            // A signature from an unrelated key. Structurally valid, wrong key.
            $other = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);
            openssl_sign($signedBytes, $signature, $other, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($signedBytes, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        }

        return [
            'id' => $this->credentialIdBase64Url(),
            'rawId' => $this->credentialIdBase64Url(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    private function clientData(string $type, string $challengeBase64Url, ?string $originOverride): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challengeBase64Url,
            'origin' => $originOverride ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * rpIdHash(32) || flags(1) || signCount(4) [ || attestedCredentialData ]
     */
    private function authenticatorData(bool $userVerified, bool $includeAttestedCredentialData): string
    {
        $flags = 0x01;                                  // UP — user present
        if ($userVerified) {
            $flags |= 0x04;                             // UV — user verified
        }
        if ($this->backupEligible) {
            $flags |= 0x08;                             // BE
        }
        if ($this->backupState) {
            $flags |= 0x10;                             // BS
        }
        if ($includeAttestedCredentialData) {
            $flags |= 0x40;                             // AT
        }

        $data = hash('sha256', $this->rpId, true)
            .chr($flags)
            .pack('N', $this->signCount);

        if ($includeAttestedCredentialData) {
            $data .= str_repeat("\0", 16)               // AAGUID
                .pack('n', strlen($this->credentialId))
                .$this->credentialId
                .$this->coseKey();
        }

        return $data;
    }

    /** COSE_Key for ES256: {1: 2, 3: -7, -1: 1, -2: x, -3: y} */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to read the test EC public key.');
        }

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))   // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))  // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))  // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));
    }
}
