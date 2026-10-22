<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends approved template messages through the WhatsApp Cloud API. Never throws, never logs the token. */
class WhatsAppSender
{
    public function configured(): bool
    {
        return filled(config('whatsapp.access_token')) && filled(config('whatsapp.phone_number_id'));
    }

    /**
     * @param  string  $phone  Indian mobile number; any format with 10 digits at the end.
     * @param  list<string>  $bodyParams  Values for {{1}}, {{2}}, ... of the template body.
     */
    public function sendTemplate(string $phone, string $template, array $bodyParams): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $digits = substr(preg_replace('/\D/', '', $phone), -10);
        if (strlen($digits) !== 10) {
            return false;
        }

        $token = (string) config('whatsapp.access_token');
        $url = 'https://graph.facebook.com/'.config('whatsapp.api_version').'/'.config('whatsapp.phone_number_id').'/messages';

        try {
            $response = Http::withToken($token)->timeout(10)->asJson()->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => '91'.$digits,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => config('whatsapp.template_language')],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $this->clean($p)], array_values($bodyParams)),
                    ]],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('WhatsApp message rejected', ['status' => $response->status(), 'error' => $response->json('error.message')]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('WhatsApp message failed', ['error' => str_replace($token, '[token]', $e->getMessage())]);

            return false;
        }
    }

    /** Template variables cannot hold new lines, tabs or runs of spaces, and are kept short. */
    private function clean(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value === '' ? '-' : mb_substr($value, 0, 60);
    }
}
