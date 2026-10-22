<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\Sms\OrderSmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sms.driver' => 'msg91',
            'sms.admin_phones' => ['9811111111'],
            'sms.msg91.auth_key' => 'test-key',
            'sms.msg91.customer_template_id' => 'tpl-customer',
            'sms.msg91.admin_template_id' => 'tpl-admin',
        ]);
    }

    private function payload(string $payment): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'base_price' => 399, 'slug' => 'choco']);
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => '0.5 kg', 'price' => 499, 'sort_order' => 0]);
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        return [
            'first_name' => 'Priya', 'last_name' => 'Sharma', 'phone' => '9876543210',
            'delivery_mode' => 'pickup', 'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'), 'scheduled_time' => '14:30',
            'payment_method' => $payment,
            'items' => [['product_slug' => 'choco', 'size_label' => '0.5 kg', 'quantity' => 1]],
        ];
    }

    public function test_cod_order_sends_one_sms_to_the_customer_and_one_to_the_shop(): void
    {
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'], 200)]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['template_id'] === 'tpl-customer' && $r['recipients'][0]['mobiles'] === '919876543210'
            && $r['recipients'][0]['name'] === 'Priya' && $r['recipients'][0]['total'] === '499');
        Http::assertSent(fn ($r) => $r['template_id'] === 'tpl-admin' && $r['recipients'][0]['mobiles'] === '919811111111'
            && $r['recipients'][0]['phone'] === '9876543210');
        $this->assertNotNull(Order::first()->confirmation_sms_sent_at);
    }

    public function test_online_order_sends_nothing_until_it_is_paid(): void
    {
        Http::fake();

        $this->postJson('/api/v1/orders', $this->payload('online'))->assertCreated();

        Http::assertNothingSent();
        $this->assertNull(Order::first()->confirmation_sms_sent_at);
    }

    public function test_confirmation_is_sent_only_once_per_order(): void
    {
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'], 200)]);
        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        app(OrderSmsNotifier::class)->confirmed(Order::first());

        Http::assertSentCount(2);
    }

    public function test_a_failing_sms_provider_never_breaks_the_order(): void
    {
        Http::fake(['control.msg91.com/*' => Http::response('boom', 500)]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_nothing_is_sent_when_the_driver_is_log(): void
    {
        config(['sms.driver' => 'log']);
        Http::fake();

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertNothingSent();
    }

    public function test_fast2sms_driver_sends_variables_in_order_through_the_dlt_route(): void
    {
        config([
            'sms.driver' => 'fast2sms',
            'sms.fast2sms.api_key' => 'f2s-key',
            'sms.fast2sms.sender_id' => 'GHRBKE',
            'sms.fast2sms.customer_message_id' => '1111',
            'sms.fast2sms.admin_message_id' => '2222',
        ]);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => true, 'message' => ['SMS sent successfully.']], 200)]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->hasHeader('authorization', 'f2s-key') && $r['route'] === 'dlt'
            && $r['message'] === '1111' && $r['numbers'] === '9876543210'
            && preg_match('/^Priya\|GB[A-Z0-9]{10}\|499\|(Delivery|Pickup)\|\d{2} \w{3}(, \d{2}:\d{2} [AP]M)?\|[A-Za-z ]+$/', $r['variables_values']) === 1);
        Http::assertSent(fn ($r) => $r['message'] === '2222' && $r['numbers'] === '9811111111');
    }

    public function test_fast2sms_rejection_does_not_break_the_order(): void
    {
        config(['sms.driver' => 'fast2sms', 'sms.fast2sms.api_key' => 'k', 'sms.fast2sms.sender_id' => 'S',
            'sms.fast2sms.customer_message_id' => '1', 'sms.fast2sms.admin_message_id' => '2']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => false, 'message' => ['Invalid']], 200)]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_fast2sms_quick_route_sends_plain_text(): void
    {
        config(['sms.driver' => 'fast2sms_quick', 'sms.fast2sms.api_key' => 'f2s-key']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => true], 200)]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['route'] === 'q' && $r['numbers'] === '9876543210'
            && str_starts_with($r['message'], 'Hi Priya, your Gharelu Bake order GB') && str_contains($r['message'], 'Rs 499'));
        Http::assertSent(fn ($r) => $r['route'] === 'q' && $r['numbers'] === '9811111111' && str_contains($r['message'], '(9876543210)'));
    }
}
