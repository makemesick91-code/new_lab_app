<?php

namespace App\Modules\DoctorDevice\Support;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — assembling the vetted verifier, once.
 *
 * WHY NONE OF THIS IS HAND-ROLLED
 *
 * Verifying a WebAuthn response means parsing CBOR, decoding a COSE key,
 * rebuilding the signed bytes, checking a hash of the relying party id,
 * comparing a challenge in constant time and validating an ECDSA signature.
 * Every one of those has a well-documented way to be subtly wrong in a way that
 * still returns "valid". So the checks come from web-auth/webauthn-lib and this
 * class does nothing but configure it correctly and in one place.
 *
 * WHAT THE LIBRARY IS CONFIGURED TO ENFORCE
 *
 * The ceremony step managers below run, per the W3C algorithms:
 *
 *   challenge match · client data type · ALLOWED ORIGIN (exact list, no
 *   subdomain wildcard) · relying party id hash · user present · USER VERIFIED
 *   · backup bits consistent · extensions · signature · counter · allowed
 *   credential list · user handle
 *
 * `setAllowedOrigins($origins, allowSubdomains: false)` is deliberate. Allowing
 * subdomains would mean any host under the clinic domain could run a ceremony,
 * and the relying party would accept it — which is precisely the property an
 * attacker who obtains a subdomain wants.
 *
 * WHY ATTESTATION IS `none`
 *
 * Attestation would let us verify the authenticator's MAKE and MODEL against a
 * metadata service. That answers "what kind of hardware is this", which is not
 * the question this feature asks. The question is "is this the SPECIFIC device
 * an administrator approved", and that is answered by the credential being
 * registered against one `mst_doctor_devices` row and then approved by a human.
 * Requesting attestation we do not verify would be theatre, and verifying it
 * properly would add a certificate-chain and metadata dependency for no gain
 * here.
 *
 * Device-boundness is a separate question again, and comes from the backup
 * flags — see WebAuthnDeviceBinding.
 */
final class WebAuthnCeremonyFactory
{
    public function __construct(private readonly WebAuthnRelyingParty $relyingParty) {}

    public function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    public function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create(
            $this->stepManagerFactory()->creationCeremony()
        );
    }

    public function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create(
            $this->stepManagerFactory()->requestCeremony()
        );
    }

    public function attestationObjectLoader(): AttestationObjectLoader
    {
        return AttestationObjectLoader::create($this->attestationSupport());
    }

    private function stepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;

        $factory->setAlgorithmManager($this->algorithmManager());
        $factory->setAttestationStatementSupportManager($this->attestationSupport());

        // Exact origins only. `allowSubdomains` stays false on purpose.
        $factory->setAllowedOrigins($this->relyingParty->allowedOrigins(), false);

        return $factory;
    }

    private function attestationSupport(): AttestationStatementSupportManager
    {
        $manager = AttestationStatementSupportManager::create();
        $manager->add(NoneAttestationStatementSupport::create());

        return $manager;
    }

    /**
     * The signature algorithms we will verify.
     *
     * `Manager::create()` takes NO arguments and algorithms are added
     * afterwards. Passing them as an array is silently ignored by PHP, which
     * yields an EMPTY manager — and an empty manager does not fail loudly at
     * construction. It fails much later, as "Unsupported algorithm" on the
     * first real assertion, which reads like a browser or authenticator problem
     * rather than a wiring one. Worth stating so it is not reintroduced.
     */
    private function algorithmManager(): AlgorithmManager
    {
        return AlgorithmManager::create()->add(
            ES256::create(),
            RS256::create(),
        );
    }
}
