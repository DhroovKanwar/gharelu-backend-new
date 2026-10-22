<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends plain-text messages through the Telegram Bot API. Never throws, never logs the bot token. */
class TelegramSender
{
    public function configured(): bool
    {
        return filled(config('telegram.bot_token')) && ! empty(config('telegram.chat_ids'));
    }

    /** True only when every chat id accepted the message. */
    public function send(string $text): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $token = (string) config('telegram.bot_token');
        $ok = true;

        foreach (config('telegram.chat_ids') as $chatId) {
            try {
                $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);

                if (! $response->successful()) {
                    $ok = false;
                    Log::warning('Telegram message rejected', ['chat' => $chatId, 'status' => $response->status(), 'body' => $response->json('description')]);
                }
            } catch (Throwable $e) {
                $ok = false;
                // Connection errors can quote the request URL, which contains the token.
                Log::warning('Telegram message failed', ['chat' => $chatId, 'error' => str_replace($token, '[token]', $e->getMessage())]);
            }
        }

        return $ok;
    }
}
