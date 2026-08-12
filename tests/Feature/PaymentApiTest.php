<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'razorpay.key_id' => 'rzp_test_public_key',
            'razorpay.key_secret' => 'test_key_secret',
            'razorpay.webhook_secret' => 'test_webhook_secret',
        ]);
    }

    private function fakeRazorpayOrder(string $razorpayOrderId = 'order_RZP123', int $amountPaise = 49900): void
    {
        Http::fake([
            'api.razorpay.com/*' => Http::response([
                'id' => $razorpayOrderId,
                'entity' => 'order',
                'amount' => $amountPaise,
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
        ]);
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'GB'.random_int(100000, 999999),
            'user_id' => null,
            'guest_name' => 'Test Customer',
            'guest_email' => 'test@example.com',
            'guest_phone' => '9999999999',
            'delivery_mode' => 'pickup',
            'pickup_type' => 'parcel',
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'subtotal' => 499,
            'delivery_fee' => 0,
            'total' => 499,
            'currency' => 'INR',
            'payment_method' => 'online',
            'payment_status' => 'pending',
            'order_status' => 'new',
        ], $attrs));
    }

    private function razorpaySignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", 'test_key_secret');
    }

    private function webhookSignature(string $rawPayload): string
    {
        return hash_hmac('sha256', $rawPayload, 'test_webhook_secret');
    }

    // ── create-order ──────────────────────────────────────────────────

    public function test_create_razorpay_order_uses_server_calculated_amount(): void
    {
        $order = $this->makeOrder(['total' => 499]);
        $this->fakeRazorpayOrder(amountPaise: 49900);

        $response = $this->postJson('/api/v1/payments/create-order', [
            'order_number' => $order->order_number,
            // Attempted manipulation — not a validated field, must be ignored.
            'amount' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 499)
            ->assertJsonPath('data.razorpayOrderId', 'order_RZP123');

        Http::assertSent(function ($request) {
            return ($request->data()['amount'] ?? null) === 49900; // 499.00 * 100, not client's "1"
        });
    }

    public function test_payment_creation_requires_valid_order(): void
    {
        $response = $this->postJson('/api/v1/payments/create-order', [
            'order_number' => 'GB000000',
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_create_payment_for_another_users_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->makeOrder(['user_id' => $owner->id]);

        $intruder = User::factory()->create();
        $intruderToken = $intruder->createToken('api')->plainTextToken;

        $this->fakeRazorpayOrder();

        $response = $this->withHeader('Authorization', "Bearer {$intruderToken}")
            ->postJson('/api/v1/payments/create-order', ['order_number' => $order->order_number]);

        $response->assertStatus(403);
    }

    public function test_guest_payment_flow_works_without_authentication(): void
    {
        $order = $this->makeOrder(); // user_id null
        $this->fakeRazorpayOrder();

        $response = $this->postJson('/api/v1/payments/create-order', [
            'order_number' => $order->order_number,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_duplicate_create_order_request_is_handled_idempotently(): void
    {
        $order = $this->makeOrder();
        $this->fakeRazorpayOrder();

        $first = $this->postJson('/api/v1/payments/create-order', ['order_number' => $order->order_number]);
        $second = $this->postJson('/api/v1/payments/create-order', ['order_number' => $order->order_number]);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(
            $first->json('data.razorpayOrderId'),
            $second->json('data.razorpayOrderId')
        );
        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_cod_order_cannot_create_razorpay_payment(): void
    {
        $order = $this->makeOrder(['payment_method' => 'cod']);
        $this->fakeRazorpayOrder();

        $response = $this->postJson('/api/v1/payments/create-order', [
            'order_number' => $order->order_number,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_alias_path_works_identically(): void
    {
        $order = $this->makeOrder();
        $this->fakeRazorpayOrder();

        // Bare path referenced by the frontend's paymentService.js, no /v1.
        $response = $this->postJson('/api/payments/create-order', [
            'order_number' => $order->order_number,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    // ── verify ────────────────────────────────────────────────────────

    public function test_successful_signature_verification_marks_order_paid(): void
    {
        $order = $this->makeOrder();
        $payment = Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_RZP123',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $signature = $this->razorpaySignature('order_RZP123', 'pay_ABC123');

        $response = $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_RZP123',
            'razorpay_payment_id' => 'pay_ABC123',
            'razorpay_signature' => $signature,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $order = $this->makeOrder();
        Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_RZP123',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $response = $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_RZP123',
            'razorpay_payment_id' => 'pay_ABC123',
            'razorpay_signature' => 'not-the-real-signature',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'pending']);
    }

    public function test_wrong_razorpay_order_id_is_rejected(): void
    {
        $signature = $this->razorpaySignature('order_DOES_NOT_EXIST', 'pay_ABC123');

        $response = $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_DOES_NOT_EXIST',
            'razorpay_payment_id' => 'pay_ABC123',
            'razorpay_signature' => $signature,
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_cannot_be_reattached_to_a_different_payment_id(): void
    {
        $order = $this->makeOrder();
        $payment = Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_RZP123',
            'razorpay_payment_id' => 'pay_ORIGINAL',
            'status' => 'captured',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);
        $order->update(['payment_status' => 'paid']);

        // Cryptographically valid signature for a DIFFERENT payment_id —
        // the defense here is the stored-payment_id mismatch check, not
        // the signature itself.
        $signature = $this->razorpaySignature('order_RZP123', 'pay_DIFFERENT');

        $response = $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_RZP123',
            'razorpay_payment_id' => 'pay_DIFFERENT',
            'razorpay_signature' => $signature,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'razorpay_payment_id' => 'pay_ORIGINAL']);
    }

    public function test_already_paid_order_reverifying_same_payment_is_idempotent(): void
    {
        $order = $this->makeOrder();
        Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_RZP123',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $signature = $this->razorpaySignature('order_RZP123', 'pay_ABC123');
        $payload = [
            'razorpay_order_id' => 'order_RZP123',
            'razorpay_payment_id' => 'pay_ABC123',
            'razorpay_signature' => $signature,
        ];

        $first = $this->postJson('/api/v1/payments/verify', $payload);
        $second = $this->postJson('/api/v1/payments/verify', $payload);

        $first->assertOk();
        $second->assertOk()->assertJsonPath('data.paymentStatus', 'paid');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid']);
    }

    // ── webhook ───────────────────────────────────────────────────────

    private function capturedWebhookPayload(string $razorpayOrderId, string $razorpayPaymentId): string
    {
        return json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $razorpayPaymentId,
                        'order_id' => $razorpayOrderId,
                        'status' => 'captured',
                    ],
                ],
            ],
        ]);
    }

    public function test_webhook_with_valid_signature_is_accepted(): void
    {
        $order = $this->makeOrder();
        Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_WEBHOOK1',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $payload = $this->capturedWebhookPayload('order_WEBHOOK1', 'pay_WH1');
        $signature = $this->webhookSignature($payload);

        $response = $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $payload);

        $response->assertOk();
        $this->assertDatabaseHas('payment_webhook_logs', ['signature_valid' => true]);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $payload = $this->capturedWebhookPayload('order_WEBHOOK1', 'pay_WH1');

        $response = $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'not-the-real-signature',
        ], $payload);

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhook_logs', 0);
    }

    public function test_duplicate_webhook_event_is_processed_only_once(): void
    {
        $order = $this->makeOrder();
        Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_WEBHOOK2',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $payload = $this->capturedWebhookPayload('order_WEBHOOK2', 'pay_WH2');
        $signature = $this->webhookSignature($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature];

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $server, $payload)->assertOk();
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], $server, $payload)->assertOk();

        $this->assertDatabaseCount('payment_webhook_logs', 1);
    }

    public function test_webhook_updates_payment_and_order_status_correctly(): void
    {
        $order = $this->makeOrder();
        $payment = Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_WEBHOOK3',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $payload = $this->capturedWebhookPayload('order_WEBHOOK3', 'pay_WH3');
        $signature = $this->webhookSignature($payload);

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured', 'razorpay_payment_id' => 'pay_WH3']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid']);
    }

    public function test_failed_payment_webhook_does_not_mark_order_as_paid(): void
    {
        $order = $this->makeOrder();
        $payment = Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => 'order_WEBHOOK4',
            'status' => 'created',
            'amount' => $order->total,
            'currency' => 'INR',
        ]);

        $payload = json_encode([
            'entity' => 'event',
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_WH4',
                        'order_id' => 'order_WEBHOOK4',
                        'status' => 'failed',
                        'error_code' => 'BAD_REQUEST_ERROR',
                        'error_description' => 'Payment failed.',
                    ],
                ],
            ],
        ]);
        $signature = $this->webhookSignature($payload);

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'failed']);
    }

    // ── secrets ───────────────────────────────────────────────────────


    public function test_payment_secrets_are_never_returned_in_api_responses(): void
    {
        $order = $this->makeOrder();
        $this->fakeRazorpayOrder();

        $createResponse = $this->postJson('/api/v1/payments/create-order', [
            'order_number' => $order->order_number,
        ]);

        $body = $createResponse->getContent();
        $this->assertStringNotContainsString('test_key_secret', $body);
        $this->assertStringNotContainsString('test_webhook_secret', $body);
        $this->assertArrayNotHasKey('razorpayKeySecret', $createResponse->json('data'));
        $this->assertArrayNotHasKey('razorpay_key_secret', $createResponse->json('data'));

        $payment = Payment::where('order_id', $order->id)->first();
        $signature = $this->razorpaySignature($payment->razorpay_order_id, 'pay_SECRETCHECK');

        $verifyResponse = $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_SECRETCHECK',
            'razorpay_signature' => $signature,
        ]);

        $verifyBody = $verifyResponse->getContent();
        $this->assertStringNotContainsString('test_key_secret', $verifyBody);
        $this->assertStringNotContainsString($signature, $verifyBody);
    }
}
