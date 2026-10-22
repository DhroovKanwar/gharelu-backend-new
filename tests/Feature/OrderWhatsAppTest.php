<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\WhatsApp\OrderWhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.access_token' => 'EAAB-SECRET', 'whatsapp.phone_number_id' => '555000111']);
        // The other notifiers stay quiet here.
        config(['mail.order_notify' => [], 'sms.driver' => 'none', 'telegram.bot_token' => null]);
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

    public function test_cod_order_sends_the_template_to_the_customer(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $params = array_column($r['template']['components'][0]['parameters'], 'text');

            return str_contains($r->url(), '/555000111/messages')
                && $r->hasHeader('Authorization', 'Bearer EAAB-SECRET')
                && $r['to'] === '919876543210'
                && $r['template']['name'] === 'order_confirmation'
                && $params[0] === 'Priya'
                && str_starts_with($params[1], 'GB')
                && $params[2] === '499';
        });
    }

    public function test_online_order_sends_nothing_until_paid_and_never_twice(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->postJson('/api/v1/orders', $this->payload('online'))->assertCreated();
        Http::assertNothingSent();

        $order = Order::first();
        app(OrderWhatsAppNotifier::class)->confirmed($order);
        app(OrderWhatsAppNotifier::class)->confirmed($order);

        Http::assertSentCount(1);
    }

    public function test_a_rejected_message_never_breaks_the_order_and_is_retried_later(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Template not approved']], 400)
            ->push(['messages' => [['id' => 'wamid.2']]])]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        $order = Order::first();
        $this->assertNull($order->confirmation_whatsapp_sent_at);

        app(OrderWhatsAppNotifier::class)->confirmed($order);

        $this->assertNotNull($order->fresh()->confirmation_whatsapp_sent_at);
    }

    public function test_nothing_is_sent_when_whatsapp_is_not_configured(): void
    {
        config(['whatsapp.access_token' => null]);
        Http::fake();

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertNothingSent();
    }
}
