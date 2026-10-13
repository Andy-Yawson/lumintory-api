<?php

namespace App\Services;

use App\Models\IntegrationApiKey;

/**
 * A short-lived, signed statement of "this Inventory user, in this tenant, is looking
 * at this page", handed to the Zinnvy AI Co-Pilot (HS256, three base64url parts). The
 * key is derived from the tenant's Zinnvy AI integration secret, which Zinnvy AI also
 * receives when the shop connects, so Zinnvy AI can check the signature and nobody else
 * can forge one. The browser only carries the token; it cannot change what is in it.
 */
class CopilotContextToken
{
    public const ISSUER = 'zinnvy-inventory';

    public const AUDIENCE = 'zinnvy-ai';

    /** What Zinnvy AI is given at connect time to verify tokens. Derived, so the raw secret never leaves Inventory. */
    public static function signingSecret(IntegrationApiKey $key): string
    {
        return hash_hmac('sha256', 'zinnvy-ai-context-v1', (string) $key->secret);
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims, IntegrationApiKey $key, int $ttlSeconds = 900): string
    {
        $now = time();
        $payload = [...$claims, 'iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'iat' => $now, 'exp' => $now + $ttlSeconds];
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($payload));

        return "{$head}.{$body}.".self::b64(hash_hmac('sha256', "{$head}.{$body}", self::signingSecret($key), true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
