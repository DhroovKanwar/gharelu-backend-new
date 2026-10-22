<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendTestSmsCommandTest extends TestCase
{
    public function test_it_sends_one_sample_sms_through_the_configured_driver(): void
    {
        config(['sms.driver' => 'fast2sms_quick', 'sms.fast2sms.api_key' => 'k']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => true], 200)]);

        $this->artisan('sms:test', ['phone' => '9876543210'])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['numbers'] === '9876543210' && str_contains($r['message'], 'GBTEST12345'));
    }

    public function test_it_fails_clearly_when_the_provider_rejects_or_the_number_is_bad(): void
    {
        config(['sms.driver' => 'fast2sms_quick', 'sms.fast2sms.api_key' => 'k']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => false], 200)]);

        $this->artisan('sms:test', ['phone' => '9876543210'])->assertFailed();
        $this->artisan('sms:test', ['phone' => '123'])->assertFailed();
    }
}
