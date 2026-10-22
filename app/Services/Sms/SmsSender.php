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
            'msg91' => $this->viaMsg91($digits, $template, $variables),
            'log' => $this->viaLog($digits, $template, $variables),
            default => false,
        };
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
