<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class AdminMarkPaidTest extends TestCase
{
    use RefreshDatabase, CreatesAdminUsers;

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'GB'.random_int(100000, 999999),
            'guest_name' => 'Test Customer',
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

    private function mark(string $token, Order $order, array $body)
    {
        return $this->withToken($token)->postJson("/api/v1/admin/orders/{$order->id}/payment", $body);
    }

    public function test_manager_marks_an_online_order_paid_and_the_audit_row_is_kept(): void
    {
        [$admin, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder();
        $payment = Payment::create(['order_id' => $order->id, 'razorpay_order_id' => 'order_AAA111', 'amount' => 499, 'status' => 'created']);

        $this->mark($token, $order, ['razorpay_payment_id' => 'pay_ABC123def456', 'remarks' => 'Paid in Razorpay, window closed early'])
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'paid')
            ->assertJsonPath('data.paymentAdjustments.0.remarks', 'Paid in Razorpay, window closed early')
            ->assertJsonPath('data.paymentAdjustments.0.razorpayPaymentId', 'pay_ABC123def456');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame('pay_ABC123def456', $payment->fresh()->razorpay_payment_id);
        $this->assertDatabaseHas('order_payment_adjustments', ['order_id' => $order->id, 'admin_id' => $admin->id, 'from_status' => 'pending', 'to_status' => 'paid']);
    }

    public function test_online_order_needs_a_payment_id_and_remarks_are_always_required(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder();

        $this->mark($token, $order, ['remarks' => 'forgot the id'])->assertStatus(422)->assertJsonValidationErrors('razorpay_payment_id');
        $this->mark($token, $order, ['razorpay_payment_id' => 'pay_ABC123def456'])->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->mark($token, $order, ['razorpay_payment_id' => 'not-a-payment-id', 'remarks' => 'bad format here'])->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_cash_on_delivery_order_can_be_marked_paid_without_a_gateway_id(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder(['payment_method' => 'cod']);

        $this->mark($token, $order, ['remarks' => 'Cash collected at the counter'])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_already_paid_orders_and_reused_payment_ids_are_refused(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $first = $this->makeOrder();
        $second = $this->makeOrder();

        $this->mark($token, $first, ['razorpay_payment_id' => 'pay_ABC123def456', 'remarks' => 'first manual update'])->assertOk();
        $this->mark($token, $first, ['razorpay_payment_id' => 'pay_ABC123def456', 'remarks' => 'second manual update'])->assertStatus(422);
        $this->mark($token, $second, ['razorpay_payment_id' => 'pay_ABC123def456', 'remarks' => 'same id on other order'])
            ->assertStatus(422)->assertJsonValidationErrors('razorpay_payment_id');

        $this->assertSame('pending', $second->fresh()->payment_status);
        $this->assertDatabaseCount('order_payment_adjustments', 1);
    }

    public function test_staff_and_anonymous_users_cannot_change_payment_status(): void
    {
        [, $staff] = $this->actingAsAdmin('staff');
        $order = $this->makeOrder();
        $body = ['razorpay_payment_id' => 'pay_ABC123def456', 'remarks' => 'trying as staff'];

        // Anonymous first: the test client keeps the last authenticated user between calls.
        $this->postJson("/api/v1/admin/orders/{$order->id}/payment", $body)->assertUnauthorized();
        $this->mark($staff, $order, $body)->assertForbidden();

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_the_status_endpoint_still_cannot_touch_payment_status(): void
    {
        [, $token] = $this->actingAsAdmin('manager');
        $order = $this->makeOrder();

        $this->withToken($token)->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'confirmed', 'payment_status' => 'paid'])->assertOk();

        $this->assertSame('pending', $order->fresh()->payment_status);
    }
}
