<?php

namespace Tests\Feature;

use App\Mail\OrderConfirmationMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Services\Mail\OrderEmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.order_notify' => ['shop@example.com']]);
        Mail::fake();
    }

    private function payload(string $payment, ?string $email): array
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'base_price' => 399, 'slug' => 'choco']);
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => '0.5 kg', 'price' => 499, 'sort_order' => 0]);
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        return [
            'first_name' => 'Priya', 'last_name' => 'Sharma', 'phone' => '9876543210', 'email' => $email,
            'delivery_mode' => 'pickup', 'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'), 'scheduled_time' => '14:30',
            'payment_method' => $payment,
            'items' => [['product_slug' => 'choco', 'size_label' => '0.5 kg', 'quantity' => 1]],
        ];
    }

    public function test_cod_order_emails_the_customer_and_the_shop(): void
    {
        $this->postJson('/api/v1/orders', $this->payload('cod', 'priya@example.com'))->assertCreated();

        Mail::assertSent(OrderConfirmationMail::class, 2);
        Mail::assertSent(OrderConfirmationMail::class, fn ($m) => $m->hasTo('priya@example.com') && ! $m->forShop);
        Mail::assertSent(OrderConfirmationMail::class, fn ($m) => $m->hasTo('shop@example.com') && $m->forShop);
    }

    public function test_order_without_an_email_only_notifies_the_shop(): void
    {
        $this->postJson('/api/v1/orders', $this->payload('cod', null))->assertCreated();

        Mail::assertSent(OrderConfirmationMail::class, 1);
        Mail::assertSent(OrderConfirmationMail::class, fn ($m) => $m->hasTo('shop@example.com'));
    }

    public function test_online_order_sends_nothing_until_paid_and_never_twice(): void
    {
        $this->postJson('/api/v1/orders', $this->payload('online', 'priya@example.com'))->assertCreated();
        Mail::assertNothingSent();

        $order = Order::first();
        app(OrderEmailNotifier::class)->confirmed($order);
        app(OrderEmailNotifier::class)->confirmed($order);

        Mail::assertSent(OrderConfirmationMail::class, 2);
    }

    public function test_email_content_has_the_order_details(): void
    {
        $this->postJson('/api/v1/orders', $this->payload('cod', 'priya@example.com'))->assertCreated();

        $html = (new OrderConfirmationMail(Order::with(['items', 'address'])->first(), forShop: true))->render();

        $this->assertStringContainsString(Order::first()->order_number, $html);
        $this->assertStringContainsString('9876543210', $html);
        $this->assertStringContainsString('₹499', $html);
    }

    public function test_nothing_is_sent_when_nobody_is_configured_to_receive(): void
    {
        config(['mail.order_notify' => []]);

        $this->postJson('/api/v1/orders', $this->payload('cod', null))->assertCreated();

        Mail::assertNothingSent();
    }
}
