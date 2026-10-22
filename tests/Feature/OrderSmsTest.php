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
}
