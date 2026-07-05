<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * OBS-1 — attaches a safe, bounded request id / correlation id to every web
 * request: request attributes, a minimal PII-free log context, and a
 * response header. Inbound client-supplied ids are only trusted when the
 * matching config flag is enabled AND the value passes strict validation;
 * otherwise a fresh id is generated. Never logs payload, cookies, session id,
 * or PII.
 */
class AttachRequestCorrelationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('observability.enabled', true)) {
            return $next($request);
        }

        $maxLength = (int) config('observability.max_id_length', 80);

        $requestId = $this->resolveId(
            $request,
            (string) config('observability.request_id.inbound_header', 'X-Request-ID'),
            (bool) config('observability.request_id.trust_inbound', false),
            $maxLength
        );

        $correlationId = $this->resolveId(
            $request,
            (string) config('observability.correlation_id.inbound_header', 'X-Correlation-ID'),
            (bool) config('observability.correlation_id.trust_inbound', false),
            $maxLength
        );

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        if ((bool) config('observability.log_context.enabled', true)) {
            Log::withContext($this->safeLogContext($request, $requestId, $correlationId));
        }

        $response = $next($request);

        if ((bool) config('observability.request_id.enabled', true)) {
            $header = (string) config('observability.request_id.response_header', 'X-Request-ID');
            if ($header !== '') {
                $response->headers->set($header, $requestId);
            }
        }

        return $response;
    }

    private function resolveId(Request $request, string $header, bool $trustInbound, int $maxLength): string
    {
        if ($trustInbound && $header !== '') {
            $inbound = $request->headers->get($header);
            if (is_string($inbound) && $this->isValidId($inbound, $maxLength)) {
                return $inbound;
            }
        }

        return Str::uuid()->toString();
    }

    private function isValidId(string $value, int $maxLength): bool
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._:-]+$/', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeLogContext(Request $request, string $requestId, string $correlationId): array
    {
        $context = [
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ];

        if ((bool) config('observability.log_context.include_method', true)) {
            $context['method'] = $request->method();
        }

        if ((bool) config('observability.log_context.include_path', true)) {
            $context['path'] = $request->path();
        }

        if ((bool) config('observability.log_context.include_route_name', true)) {
            $context['route'] = $request->route()?->getName();
        }

        if ((bool) config('observability.log_context.include_user_id', false)) {
            $context['user_id'] = $request->user()?->getAuthIdentifier();
        }

        if ((bool) config('observability.log_context.include_branch_id', false)) {
            $context['branch_id'] = $request->attributes->get('branch_id');
        }

        return $context;
    }
}
