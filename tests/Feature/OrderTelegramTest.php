<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\Telegram\OrderTelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.bot_token' => '123:SECRET', 'telegram.chat_ids' => ['111', '222']]);
        // Other notifiers stay quiet: nothing configured for them here.
        config(['mail.order_notify' => [], 'sms.driver' => 'none']);
    }

    private function payload(string $payment): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'base_price' => 399, 'slug' => 'choco', 'name' => 'Choco Cake']);
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

    public function test_cod_order_alerts_every_chat_with_the_order_details(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bot123:SECRET/sendMessage')
            && $r['chat_id'] === '111'
            && str_contains($r['text'], 'Choco Cake')
            && str_contains($r['text'], 'Priya Sharma, 9876543210')
            && str_contains($r['text'], 'Rs 499'));
    }

    public function test_online_order_sends_nothing_until_paid_and_never_twice(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/v1/orders', $this->payload('online'))->assertCreated();
        Http::assertNothingSent();

        $order = Order::first();
        app(OrderTelegramNotifier::class)->confirmed($order);
        app(OrderTelegramNotifier::class)->confirmed($order);

        Http::assertSentCount(2);
    }

    public function test_a_failed_alert_never_breaks_the_order_and_is_retried_later(): void
    {
        // Two chats rejected on the first try, both accepted on the retry.
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => 'chat not found'], 400)
            ->push(['ok' => false, 'description' => 'chat not found'], 400)
            ->push(['ok' => true])
            ->push(['ok' => true])]);

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        $order = Order::first();
        $this->assertNull($order->confirmation_telegram_sent_at);

        app(OrderTelegramNotifier::class)->confirmed($order);

        $this->assertNotNull($order->fresh()->confirmation_telegram_sent_at);
    }

    public function test_nothing_is_sent_when_telegram_is_not_configured(): void
    {
        config(['telegram.bot_token' => null]);
        Http::fake();

        $this->postJson('/api/v1/orders', $this->payload('cod'))->assertCreated();

        Http::assertNothingSent();
    }
}
