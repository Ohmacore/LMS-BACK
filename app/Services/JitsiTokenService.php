<?php

namespace App\Services;

use App\Models\LiveSession;
use App\Models\User;

class JitsiTokenService
{
    public function urlForSession(LiveSession $session, User $user, bool $moderator = false): string
    {
        if ($session->provider !== 'jitsi' || !$session->provider_room) {
            return $moderator
                ? ($session->start_url ?: $session->zoom_start_url ?: '')
                : ($session->join_url ?: $session->zoom_join_url ?: '');
        }

        $url = $this->roomUrl($session->provider_room);
        $token = $this->tokenForRoom($session->provider_room, $user, $moderator);

        if (!$token) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'jwt=' . rawurlencode($token);
    }

    public function roomUrl(string $room): string
    {
        return rtrim((string) config('services.jitsi.base_url', 'https://meet.jit.si'), '/') . '/' . rawurlencode($room);
    }

    public function tokenForRoom(string $room, User $user, bool $moderator = false): ?string
    {
        $appId = config('services.jitsi.jwt_app_id');
        $secret = config('services.jitsi.jwt_app_secret');

        if (!$appId || !$secret) {
            return null;
        }

        $now = now()->timestamp;
        $ttl = max(15, (int) config('services.jitsi.jwt_ttl_minutes', 240));

        return $this->encode([
            'aud' => 'jitsi',
            'iss' => $appId,
            'sub' => $this->domain(),
            'room' => $room,
            'iat' => $now - 5,
            'nbf' => $now - 5,
            'exp' => $now + ($ttl * 60),
            'context' => [
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $this->displayName($user),
                    'email' => $user->email,
                    'moderator' => $moderator,
                    'affiliation' => $moderator ? 'owner' : 'member',
                ],
                'features' => [
                    'livestreaming' => false,
                    'recording' => false,
                    'transcription' => false,
                    'outbound-call' => false,
                ],
            ],
        ], $secret);
    }

    private function encode(array $payload, string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', "{$header}.{$body}", $secret, true);

        return "{$header}.{$body}." . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function domain(): string
    {
        $host = parse_url((string) config('services.jitsi.base_url'), PHP_URL_HOST);

        return $host ?: 'meet.jitsi';
    }

    private function displayName(User $user): string
    {
        $name = trim((string) ($user->name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));

        return $name ?: ($user->pseudo ?: $user->email);
    }
}
