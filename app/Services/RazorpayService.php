<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Deliberately uses Laravel's built-in HTTP client rather than the
 * razorpay/razorpay SDK — one fewer dependency, and it makes tests trivial
 * via Http::fake() without any custom mocking layer.
 */
class RazorpayService
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function createOrder(string $receipt, float $amount, string $currency = 'INR'): array
    {
        $response = Http::withBasicAuth(
            (string) config('razorpay.key_id'),
            (string) config('razorpay.key_secret'),
        )->asJson()->post(self::BASE_URL.'/orders', [
            // Razorpay expects the amount in the smallest currency unit
            // (paise for INR) — always derived from the server-calculated
            // order total, never from anything the client sends.
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'receipt' => $receipt,
            'payment_capture' => 1,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to create Razorpay order.');
        }

        return $response->json();
    }

    public function verifySignature(string $razorpayOrderId, string $razorpayPaymentId, string $signature): bool
    {
        $expected = hash_hmac(
            'sha256',
            $razorpayOrderId.'|'.$razorpayPaymentId,
            (string) config('razorpay.key_secret'),
        );

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawPayload, (string) config('razorpay.webhook_secret'));

        return hash_equals($expected, $signature);
    }
}
