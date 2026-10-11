<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin Shiprocket client: auth + courier serviceability/rate lookup.
 * Credentials live in .env only (see config/services.php).
 */
class ShiprocketService
{
    private const BASE_URL = 'https://apiv2.shiprocket.in/v1/external';

    public function isConfigured(): bool
    {
        return filled(config('services.shiprocket.email'))
            && filled(config('services.shiprocket.password'))
            && filled(config('services.shiprocket.pickup_pincode'));
    }

    /**
     * Cheapest available courier for this pincode/weight, or null when the
     * pincode is not serviceable or Shiprocket could not be reached.
     *
     * @return array{rate: float, courier: string, etd: ?string}|null
     */
    public function cheapestRate(string $deliveryPincode, float $weightKg, bool $cod = false): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $cacheKey = sprintf('shiprocket.rate.%s.%s.%d', $deliveryPincode, number_format($weightKg, 2), $cod);

        // Only successful quotes are cached; a miss is retried next time.
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::withToken($this->token())
                ->timeout(8)
                ->get(self::BASE_URL.'/courier/serviceability/', [
                    'pickup_postcode' => config('services.shiprocket.pickup_pincode'),
                    'delivery_postcode' => $deliveryPincode,
                    'weight' => $weightKg,
                    'cod' => $cod ? 1 : 0,
                ]);

            if ($response->status() === 401) {
                Cache::forget('shiprocket.token');
            }

            $couriers = $response->json('data.available_courier_companies') ?? [];
        } catch (Throwable $e) {
            Log::warning('Shiprocket rate lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (empty($couriers)) {
            return null;
        }

        usort($couriers, fn ($a, $b) => (float) $a['rate'] <=> (float) $b['rate']);
        $best = $couriers[0];

        $quote = [
            'rate' => round((float) $best['rate'], 2),
            'courier' => (string) ($best['courier_name'] ?? ''),
            'etd' => $best['etd'] ?? null,
        ];

        Cache::put($cacheKey, $quote, now()->addHours(6));

        return $quote;
    }

    public function token(): string
    {
        $cached = Cache::get('shiprocket.token');
        if ($cached) {
            return $cached;
        }

        // Shiprocket blocks the account after a few failed logins, so after
        // one failure stop retrying for a while instead of hammering it.
        if (Cache::get('shiprocket.login_failed')) {
            throw new \RuntimeException('Shiprocket login suspended after a failed attempt.');
        }

        $response = Http::timeout(8)->post(self::BASE_URL.'/auth/login', [
            'email' => config('services.shiprocket.email'),
            'password' => config('services.shiprocket.password'),
        ]);

        $token = $response->json('token');

        if (! $response->successful() || ! $token) {
            Cache::put('shiprocket.login_failed', true, now()->addMinutes(30));
            throw new \RuntimeException('Shiprocket login failed: HTTP '.$response->status());
        }

        Cache::put('shiprocket.token', $token, now()->addDays(8));

        return $token;
    }
}
