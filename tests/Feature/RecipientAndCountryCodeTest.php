<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sender phone with a country code, and a separate recipient (name + phone) for deliveries.
 */
class RecipientAndCountryCodeTest extends TestCase
{
    use \Tests\Concerns\FakesShiprocket;
    use \Tests\Concerns\MakesHampers;
    use RefreshDatabase;

    private function product(bool $hamper = false): Product
    {
        $product = Product::factory()->create(['category_id' => Category::factory()->create()->id, 'base_price' => 500, 'slug' => 'choco-'.uniqid()]);
        ProductSize::factory()->create(['product_id' => $product->id, 'label' => 'Box', 'price' => 500, 'sort_order' => 0]);
        ProductImage::factory()->create(['product_id' => $product->id, 'is_primary' => true]);

        return $hamper ? $this->asHamper($product) : $product;
    }

    private function pickup(Product $product, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Priya', 'last_name' => 'Sharma', 'phone' => '9876543210',
            'delivery_mode' => 'pickup', 'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'), 'scheduled_time' => '14:30',
            'payment_method' => 'cod',
            'items' => [['product_slug' => $product->slug, 'size_label' => 'Box', 'quantity' => 1]],
        ], $overrides);
    }

    private function delivery(Product $product, array $overrides = []): array
    {
        return array_merge($this->pickup($product, [
            'delivery_mode' => 'delivery', 'pickup_type' => null, 'payment_method' => 'online',
            'address_line_1' => '221B Baker Street', 'city' => 'Mohali', 'state' => 'Punjab', 'pincode' => '140603',
            'recipient_first_name' => 'Anita', 'recipient_last_name' => 'Verma', 'recipient_phone' => '9811122233',
        ]), $overrides);
    }

    public function test_a_delivery_order_stores_who_it_is_for_and_the_sender_country_code(): void
    {
        config(['services.delivery.near_pincodes' => ['140603']]);
        $product = $this->product(hamper: true);

        $this->postJson('/api/v1/orders', $this->delivery($product, ['phone_country_code' => '+91']))
            ->assertCreated()
            ->assertJsonPath('data.customer.phoneCountryCode', '+91')
            ->assertJsonPath('data.address.recipient.name', 'Anita Verma')
            ->assertJsonPath('data.address.recipient.phone', '9811122233')
            ->assertJsonPath('data.address.recipient.phoneCountryCode', '+91');

        $address = Address::first();
        $this->assertSame('Anita Verma', $address->recipient_name);
        $this->assertSame('+91', $address->recipient_phone_country_code);
    }

    public function test_the_recipient_is_required_for_delivery_but_not_for_pickup(): void
    {
        config(['services.delivery.near_pincodes' => ['140603']]);
        $hamper = $this->product(hamper: true);

        $this->postJson('/api/v1/orders', $this->delivery($hamper, ['recipient_first_name' => '', 'recipient_phone' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_first_name', 'recipient_phone']);

        $this->postJson('/api/v1/orders', $this->pickup($this->product()))->assertCreated();
        $this->assertNull(Order::first()->address_id);
    }

    public function test_a_recipient_last_name_is_optional(): void
    {
        config(['services.delivery.near_pincodes' => ['140603']]);

        $this->postJson('/api/v1/orders', $this->delivery($this->product(hamper: true), ['recipient_last_name' => '']))
            ->assertCreated()
            ->assertJsonPath('data.address.recipient.name', 'Anita');
    }

    public function test_the_recipient_must_have_an_indian_number(): void
    {
        config(['services.delivery.near_pincodes' => ['140603']]);

        $this->postJson('/api/v1/orders', $this->delivery($this->product(hamper: true), ['recipient_phone_country_code' => '+44', 'recipient_phone' => '7911123456']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_phone_country_code');
    }

    public function test_an_overseas_sender_is_accepted_with_their_country_code(): void
    {
        $this->postJson('/api/v1/orders', $this->pickup($this->product(), ['phone_country_code' => '+44', 'phone' => '07911 123456']))
            ->assertCreated()
            ->assertJsonPath('data.customer.phone', '7911123456')
            ->assertJsonPath('data.customer.phoneCountryCode', '+44');
    }

    public function test_phone_numbers_are_checked_against_their_country(): void
    {
        $product = $this->product();

        // India: exactly 10 digits starting 6-9
        $this->postJson('/api/v1/orders', $this->pickup($product, ['phone' => '123456789']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/orders', $this->pickup($product, ['phone' => '1234567890']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        // Other countries: 6-14 digits
        $this->postJson('/api/v1/orders', $this->pickup($product, ['phone_country_code' => '+1', 'phone' => '123']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        // Not a country code
        $this->postJson('/api/v1/orders', $this->pickup($product, ['phone_country_code' => '+abc']))->assertCreated(); // junk falls back to +91
    }

    public function test_no_country_code_means_india_as_before(): void
    {
        $this->postJson('/api/v1/orders', $this->pickup($this->product(), ['phone' => '+91 98765 43210']))
            ->assertCreated()
            ->assertJsonPath('data.customer.phone', '9876543210')
            ->assertJsonPath('data.customer.phoneCountryCode', '+91');
    }

    public function test_an_overseas_sender_gets_no_customer_sms_but_the_shop_is_told(): void
    {
        config([
            'sms.driver' => 'msg91', 'sms.admin_phones' => ['9811111111'], 'sms.msg91.auth_key' => 'k',
            'sms.msg91.customer_template_id' => 'tpl-customer', 'sms.msg91.admin_template_id' => 'tpl-admin',
        ]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'], 200)]);

        $this->postJson('/api/v1/orders', $this->pickup($this->product(), ['phone_country_code' => '+44', 'phone' => '7911123456']))->assertCreated();

        // Only the shop's SMS: a UK number's last 10 digits must never be texted as if they were Indian.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['template_id'] === 'tpl-admin' && $r['recipients'][0]['mobiles'] === '919811111111'
            && $r['recipients'][0]['phone'] === '+447911123456');
    }
}
