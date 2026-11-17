<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pixelforge-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" keyed with the webhook secret>.
 */
class VerifyPixelforgeSignature
{
    public const string HEADER = 'Pixelforge-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        // An empty key would let anyone compute a valid signature. 500 so Pixelforge retries once it is configured.
        if ($secret === '') {
            report(new RuntimeException('PIXELFORGE_WEBHOOK_SECRET is not set; rejecting webhook.'));

            return response('Webhook secret not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signature = [];

        foreach (explode(',', (string) $request->header(self::HEADER)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);
        $isFresh = abs(time() - $timestamp) <= config('services.pixelforge.webhook_tolerance_seconds');

        if (! $isFresh || ! hash_equals($expected, $signature['v1'] ?? '')) {
            return response('Invalid Pixelforge signature.', Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}
