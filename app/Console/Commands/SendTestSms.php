<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsSender;
use Illuminate\Console\Command;

class SendTestSms extends Command
{
    protected $signature = 'sms:test {phone : 10-digit mobile number to send the sample SMS to} {name=Test : first name shown in the SMS}';

    protected $description = 'Send one sample order-confirmation SMS with the configured driver (costs one SMS).';

    public function handle(SmsSender $sms): int
    {
        $driver = (string) config('sms.driver');
        $this->line("SMS driver: {$driver}");

        $sent = $sms->send($this->argument('phone'), 'customer', [
            'name' => $this->argument('name'),
            'order' => 'GBTEST12345',
            'total' => '499',
            'mode' => 'Delivery',
            'when' => now('Asia/Kolkata')->format('d M'),
            'pay' => 'Paid online',
        ]);

        if ($sent) {
            $this->info($driver === 'log' ? 'Written to the log only (driver is "log"); nothing was sent.' : 'Accepted by the SMS provider. Check the phone.');

            return self::SUCCESS;
        }

        $this->error('Not sent. Check storage/logs/laravel.log for the provider response, and that the .env keys are set.');

        return self::FAILURE;
    }
}
