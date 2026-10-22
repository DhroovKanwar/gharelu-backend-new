<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one templated SMS. Indian transactional SMS must use a DLT-approved
 * template, so the provider is given a template id plus the variable values.
 */
class SmsSender
{
    private const FAST2SMS_URL = 'https://www.fast2sms.com/dev/bulkV2';

    private const MSG91_FLOW_URL = 'https://control.msg91.com/api/v5/flow';

    /** DLT variables are usually capped at 30 characters. */
    private const MAX_VARIABLE_LENGTH = 30;

    /**
     * @param  string  $phone  Any format; only the last 10 digits are used (Indian numbers).
     * @param  string  $template  "customer" or "admin"
     * @param  array<string, scalar>  $variables
     */
    public function send(string $phone, string $template, array $variables): bool
    {
        $digits = substr(preg_replace('/\D/', '', $phone), -10);
        if (strlen($digits) !== 10) {
            return false;
        }

        $variables = array_map(fn ($v) => mb_substr((string) $v, 0, self::MAX_VARIABLE_LENGTH), $variables);

        return match (config('sms.driver')) {
            'fast2sms_quick' => $this->viaFast2smsQuick($digits, $template, $variables),
            'fast2sms' => $this->viaFast2sms($digits, $template, $variables),
            'msg91' => $this->viaMsg91($digits, $template, $variables),
            'log' => $this->viaLog($digits, $template, $variables),
            default => false,
        };
    }

    /**
     * Fast2SMS "q" (Quick SMS) route: sends plain text with no DLT template, at a higher
     * per-SMS price. Meant as a stop-gap until DLT is approved.
     */
    private function viaFast2smsQuick(string $digits, string $template, array $variables): bool
    {
        $apiKey = config('sms.fast2sms.api_key');
        $text = config("sms.messages.{$template}");

        if (! $apiKey || ! $text) {
            Log::warning('SMS skipped: Fast2SMS quick route is not fully configured', ['template' => $template]);

            return false;
        }

        $message = strtr($text, array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($variables)),
            array_values($variables),
        ));

        $response = Http::withHeaders(['authorization' => $apiKey])
            ->acceptJson()
            ->timeout(8)
            ->post(self::FAST2SMS_URL, [
                'route' => 'q',
                'message' => $message,
                'numbers' => $digits,
                'flash' => 0,
            ]);

        if (! $response->successful() || $response->json('return') !== true) {
            Log::warning('Fast2SMS quick route rejected an SMS', [
                'template' => $template,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Fast2SMS "dlt" route. The template's variables are sent as one pipe-separated
     * string, so the DLT template must list them in the same order as the notifier
     * passes them (customer: name, order, total, date; admin: order, name, phone,
     * total, mode, date).
     */
    private function viaFast2sms(string $digits, string $template, array $variables): bool
    {
        $apiKey = config('sms.fast2sms.api_key');
        $senderId = config('sms.fast2sms.sender_id');
        $messageId = config("sms.fast2sms.{$template}_message_id");

        if (! $apiKey || ! $senderId || ! $messageId) {
            Log::warning('SMS skipped: Fast2SMS is not fully configured', ['template' => $template]);

            return false;
        }

        $response = Http::withHeaders(['authorization' => $apiKey])
            ->acceptJson()
            ->timeout(8)
            ->post(self::FAST2SMS_URL, [
                'route' => 'dlt',
                'sender_id' => $senderId,
                'message' => $messageId,
                // "|" separates values, so it must never appear inside one.
                'variables_values' => implode('|', array_map(fn ($v) => str_replace('|', ' ', (string) $v), array_values($variables))),
                'numbers' => $digits,
                'flash' => 0,
            ]);

        if (! $response->successful() || $response->json('return') !== true) {
            Log::warning('Fast2SMS rejected an SMS', [
                'template' => $template,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    private function viaMsg91(string $digits, string $template, array $variables): bool
    {
        $templateId = config("sms.msg91.{$template}_template_id");
        $authKey = config('sms.msg91.auth_key');

        if (! $templateId || ! $authKey) {
            Log::warning('SMS skipped: MSG91 is not fully configured', ['template' => $template]);

            return false;
        }

        $response = Http::withHeaders(['authkey' => $authKey])
            ->acceptJson()
            ->timeout(8)
            ->post(self::MSG91_FLOW_URL, [
                'template_id' => $templateId,
                'short_url' => '0',
                'recipients' => [['mobiles' => '91'.$digits] + $variables],
            ]);

        if (! $response->successful() || $response->json('type') === 'error') {
            Log::warning('MSG91 rejected an SMS', [
                'template' => $template,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }

    private function viaLog(string $digits, string $template, array $variables): bool
    {
        // Phone is masked: logs are not the place for full customer numbers.
        Log::info('SMS (log driver)', ['to' => '******'.substr($digits, -4), 'template' => $template, 'variables' => $variables]);

        return true;
    }
}
