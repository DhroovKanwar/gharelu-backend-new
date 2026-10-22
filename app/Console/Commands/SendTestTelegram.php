<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramSender;
use Illuminate\Console\Command;

class SendTestTelegram extends Command
{
    protected $signature = 'telegram:test';

    protected $description = 'Send one test message to every configured Telegram chat id.';

    public function handle(TelegramSender $telegram): int
    {
        if (! $telegram->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_IDS in .env first.');

            return self::FAILURE;
        }

        if ($telegram->send('Gharelu Bake: Telegram alerts are working.')) {
            $this->info('Sent. Check Telegram.');

            return self::SUCCESS;
        }

        $this->error('Not sent. Check storage/logs/laravel.log (the chat must have messaged the bot first).');

        return self::FAILURE;
    }
}
