<?php

namespace App\Lib;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie via proxycheck.io qu'une IP est celle d'un utilisateur (box, mobile)
 * et pas celle d'un VPN, d'un proxy ou d'un serveur (hébergeur, robot).
 */
class IpReputation
{
    /**
     * Durée de mise en cache du verdict pour une IP, pour économiser le quota de l'API
     */
    public const CACHE_DAYS = 30;

    /**
     * Durée de mise en cache quand l'API n'a pas répondu, pour ne pas la marteler
     */
    public const ERROR_CACHE_MINUTES = 10;

    public function isRealUser(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $cacheKey = 'ip_reputation:'.$ip;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'user';
        }

        $verdict = $this->fetchVerdict($ip);
        if ($verdict === null) {
            Cache::put($cacheKey, 'unknown', now()->addMinutes(self::ERROR_CACHE_MINUTES));

            return false;
        }

        Cache::put($cacheKey, $verdict, now()->addDays(self::CACHE_DAYS));

        return $verdict === 'user';
    }

    /**
     * @return string|null 'user', 'proxy' ou null si l'API n'a pas pu répondre
     */
    private function fetchVerdict(string $ip): ?string
    {
        try {
            $response = Http::timeout(3)->get(config('services.proxycheck.url').$ip, array_filter([
                'vpn' => 1,
                'key' => config('services.proxycheck.key'),
            ]));
        } catch (\Throwable $exception) {
            Log::warning('proxycheck.io injoignable : '.$exception->getMessage());

            return null;
        }

        $result = ($response->json() ?? [])[$ip] ?? null;
        if (! $response->successful() || ! is_array($result) || ! isset($result['proxy'])) {
            Log::warning('proxycheck.io sans verdict pour '.$ip.' : '.$response->json('status').' '.$response->json('message'));

            return null;
        }

        return $result['proxy'] === 'no' ? 'user' : 'proxy';
    }
}
