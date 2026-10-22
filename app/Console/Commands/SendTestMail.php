<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : email address to send the test message to}';

    protected $description = 'Send one plain test email with the configured mail settings.';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $this->line("Mail driver: {$mailer}");

        try {
            Mail::raw('This is a test email from Gharelu Bake. Your mail settings work.', function ($message) {
                $message->to($this->argument('to'))->subject('Gharelu Bake mail test');
            });
        } catch (Throwable $e) {
            $this->error('Not sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($mailer === 'log' ? 'Written to the log only (MAIL_MAILER=log); nothing was sent.' : 'Sent. Check the inbox (and spam).');

        return self::SUCCESS;
    }
}
