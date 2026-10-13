<?php

namespace App\Services;

use App\Models\AuthRefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signed-in sessions = a short-lived access token + a rotating refresh token, per device.
 *
 * - Access tokens last ACCESS_HOURS. When one expires mid-action the app refreshes silently
 *   and retries, so people aren't thrown out while working.
 * - A refresh token lasts REFRESH_DAYS from its last use (sliding), up to MAX_SESSION_DAYS
 *   in total, after which signing in again is required.
 * - Rotation: each refresh token works once. Reusing one after the grace window means it was
 *   copied, so that device's whole session is revoked.
 * - Signing in on one device no longer signs you out everywhere else.
 */
class AuthSessions
{
    public const ACCESS_HOURS = 2;
    public const REFRESH_DAYS = 30;
    public const MAX_SESSION_DAYS = 180;
    /** Two tabs refreshing at the same moment both present the same token: allow that. */
    public const REUSE_GRACE_SECONDS = 30;

    /** @return array{token: string, refresh_token: string, expires_in: int} */
    public static function issue(User $user, ?string $family = null, ?\DateTimeInterface $familyStarted = null, ?string $userAgent = null): array
    {
        $access = $user->createToken('auth_token', ['*'], now()->addHours(self::ACCESS_HOURS));
        $refresh = Str::random(64);

        AuthRefreshToken::create([
            'user_id' => $user->id,
            'family' => $family ?? (string) Str::uuid(),
            'token_hash' => hash('sha256', $refresh),
            'access_token_id' => $access->accessToken->id,
            'expires_at' => now()->addDays(self::REFRESH_DAYS),
            'family_started_at' => $familyStarted ?? now(),
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ]);

        return ['token' => $access->plainTextToken, 'refresh_token' => $refresh, 'expires_in' => self::ACCESS_HOURS * 3600];
    }

    /**
     * Swap a refresh token for a new pair.
     *
     * @return array{token: string, refresh_token: string, expires_in: int, user: User}|string  pair, or a reason: invalid|expired|reused|inactive
     */
    public static function refresh(string $plain, ?string $userAgent = null): array|string
    {
        return DB::transaction(function () use ($plain, $userAgent) {
            $row = AuthRefreshToken::where('token_hash', hash('sha256', $plain))->lockForUpdate()->first();
            if (! $row || $row->revoked_at) {
                return 'invalid';
            }
            if ($row->used_at && $row->used_at->lt(now()->subSeconds(self::REUSE_GRACE_SECONDS))) {
                self::revokeFamily($row->family); // a copied token: end that device's session

                return 'reused';
            }
            if ($row->expires_at->isPast() || $row->family_started_at->lt(now()->subDays(self::MAX_SESSION_DAYS))) {
                return 'expired';
            }

            $user = User::with('tenant')->find($row->user_id);
            if (! $user) {
                return 'invalid';
            }
            if ($user->role !== 'SuperAdmin' && ! $user->tenant?->is_active) {
                return 'inactive';
            }

            if (! $row->used_at) {
                $row->forceFill(['used_at' => now()])->save();
                // The old access token is retired with its refresh token.
                PersonalAccessToken::whereKey($row->access_token_id)->delete();
            }
            $user->tenant?->update(['last_active_at' => now()]);

            return self::issue($user, $row->family, $row->family_started_at, $userAgent) + ['user' => $user];
        });
    }

    /** Sign out this device: its refresh tokens and the access tokens issued with them. */
    public static function revokeFamily(string $family): void
    {
        $rows = AuthRefreshToken::where('family', $family)->get(['id', 'access_token_id']);
        PersonalAccessToken::whereIn('id', $rows->pluck('access_token_id')->filter())->delete();
        AuthRefreshToken::where('family', $family)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function revokeForAccessToken(?int $accessTokenId): void
    {
        if ($accessTokenId && ($family = AuthRefreshToken::where('access_token_id', $accessTokenId)->value('family'))) {
            self::revokeFamily($family);
        }
    }

    /** Sign out everywhere (removed from a team, etc.). */
    public static function revokeAllFor(User $user): void
    {
        $user->tokens()->delete();
        AuthRefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function prune(): int
    {
        return AuthRefreshToken::where(fn ($q) => $q->where('expires_at', '<', now()->subDay())->orWhere('revoked_at', '<', now()->subDay()))->delete();
    }
}
