<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;

class SendTestWhatsApp extends Command
{
    protected $signature = 'whatsapp:test {phone : 10-digit mobile number to send the sample message to}';

    protected $description = 'Send one sample order-confirmation WhatsApp message with the configured template.';

    public function handle(WhatsAppSender $whatsapp): int
    {
        if (! $whatsapp->configured()) {
            $this->error('Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env first.');

            return self::FAILURE;
        }

        $sent = $whatsapp->sendTemplate($this->argument('phone'), (string) config('whatsapp.customer_template'), [
            'Test', 'GBTEST12345', '499', now('Asia/Kolkata')->format('d M'),
        ]);

        if ($sent) {
            $this->info('Accepted by WhatsApp. Check the phone.');

            return self::SUCCESS;
        }

        $this->error('Not sent. Check storage/logs/laravel.log: the template must be approved, and while testing the number must be on the allowed list in Meta.');

        return self::FAILURE;
    }
}
