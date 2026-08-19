<?php

namespace App\Modules\Prescription\Gateways;

use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — the official Meta WhatsApp
 * Business Platform (Cloud API) gateway.
 *
 * Verified against Meta's current Cloud API documentation:
 *   POST {base}/{version}/{PHONE_NUMBER_ID}/messages
 *   Authorization: Bearer <ACCESS_TOKEN>
 *   {"messaging_product":"whatsapp","recipient_type":"individual",
 *    "to":"<E164>","type":"template","template":{...}}
 *
 * Only template messages are delivered outside an open 24-hour customer
 * service window, which is why a proactive prescription always sends one.
 *
 * Safety posture:
 *   - the outbound host is checked against an allowlist and must be HTTPS
 *     (SSRF boundary) BEFORE any request is made;
 *   - redirects are never followed;
 *   - the access token is never logged, never returned and never persisted;
 *   - the provider response is reduced to a sanitized value object.
 */
final class CloudApiWhatsAppGateway implements WhatsAppGatewayInterface
{
    /**
     * Provider error codes that describe a transient condition. Sourced from
     * Meta's published Cloud API error-code reference.
     */
    private const RETRYABLE_CODES = [
        '4',        // app API call rate limit
        '80007',    // WhatsApp Business Account rate limit
        '130429',   // Cloud API throughput reached
        '131056',   // pair rate limit
        '133016',   // temporary service issue
        '500', '502', '503', '504',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly SensitiveValueMasker $masker,
        private readonly array $config,
        private readonly array $allowedHosts,
    ) {}

    public function isEnabled(): bool
    {
        return true;
    }

    public function assertReadyToSend(): void
    {
        foreach (['phone_number_id' => 'ID nomor telepon', 'access_token' => 'token akses'] as $key => $label) {
            if (blank($this->config[$key] ?? null)) {
                throw WhatsAppDeliveryException::misconfigured($label);
            }
        }

        $this->assertAllowedEndpoint();
    }

    public function sendTemplate(
        string $recipientMsisdn,
        string $templateName,
        string $languageCode,
        array $bodyParameters = [],
    ): WhatsAppApiResponse {
        $this->assertReadyToSend();

        if (blank($templateName)) {
            throw WhatsAppDeliveryException::misconfigured('nama template resep');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipientMsisdn,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if ($bodyParameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    array_values($bodyParameters),
                ),
            ]];
        }

        try {
            $response = $this->http
                ->withToken((string) $this->config['access_token'])
                ->acceptJson()
                ->asJson()
                ->timeout((int) ($this->config['timeout_seconds'] ?? 15))
                ->withoutRedirecting()
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // Never echo the exception verbatim: it can contain the full URL.
            Log::warning('WhatsApp prescription delivery could not reach the provider.', [
                'reason' => $this->masker->mask($e->getMessage()),
            ]);

            return WhatsAppApiResponse::failure(
                'connection',
                'Tidak dapat menghubungi layanan WhatsApp. Coba lagi beberapa saat lagi.',
                retryable: true,
            );
        }

        if ($response->successful()) {
            $messageId = $response->json('messages.0.id');

            return WhatsAppApiResponse::success(is_string($messageId) ? $messageId : null);
        }

        $code = $response->json('error.code');
        $code = $code === null ? (string) $response->status() : (string) $code;
        $providerMessage = (string) ($response->json('error.error_data.details')
            ?? $response->json('error.message')
            ?? 'Pengiriman ditolak oleh layanan WhatsApp.');

        Log::warning('WhatsApp prescription delivery was rejected by the provider.', [
            'provider_error_code' => $code,
            'provider_error' => $this->masker->mask($providerMessage),
        ]);

        return WhatsAppApiResponse::failure(
            $code,
            $this->masker->mask($this->operatorMessageFor($code, $providerMessage)),
            retryable: in_array($code, self::RETRYABLE_CODES, true),
        );
    }

    private function endpoint(): string
    {
        return rtrim((string) $this->config['base_url'], '/')
            .'/'.trim((string) $this->config['graph_version'], '/')
            .'/'.trim((string) $this->config['phone_number_id'], '/')
            .'/messages';
    }

    /**
     * SSRF boundary — refuse anything that is not an allowlisted HTTPS host.
     */
    private function assertAllowedEndpoint(): void
    {
        $base = (string) ($this->config['base_url'] ?? '');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            throw WhatsAppDeliveryException::misconfigured('alamat API harus HTTPS');
        }

        if (! in_array(strtolower($host), array_map('strtolower', $this->allowedHosts), true)) {
            throw WhatsAppDeliveryException::misconfigured('alamat API tidak diizinkan');
        }
    }

    /**
     * Translate the provider's answer into something a clinic operator can act
     * on, without leaking provider internals.
     */
    private function operatorMessageFor(string $code, string $providerMessage): string
    {
        return match ($code) {
            '131026' => 'Nomor tujuan tidak terdaftar di WhatsApp atau tidak dapat menerima pesan.',
            '131047' => 'Pesan harus dikirim menggunakan template yang disetujui. Periksa konfigurasi template resep.',
            '131021' => 'Nomor tujuan sama dengan nomor pengirim klinik.',
            '132000', '132001', '132005', '132007', '132012', '132015' => 'Template resep belum disetujui atau tidak cocok. Periksa status template di WhatsApp Manager.',
            '190', '0' => 'Token akses WhatsApp tidak valid atau kedaluwarsa. Hubungi administrator.',
            '4', '80007', '130429', '131056' => 'Batas pengiriman WhatsApp tercapai. Coba lagi beberapa saat lagi.',
            default => $providerMessage,
        };
    }
}
