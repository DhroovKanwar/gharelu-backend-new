<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #2b2b2b; max-width: 560px; margin: 0 auto; padding: 16px;">
    <h2 style="margin-bottom: 4px;">{{ $forShop ? 'New order received' : 'Thank you, '.strtok($order->guest_name, ' ').'!' }}</h2>
    <p style="margin-top: 0; color: #666;">
        {{ $forShop ? 'Order' : 'Your order' }} <strong>{{ $order->order_number }}</strong> is confirmed.
    </p>

    @if ($forShop)
        <p>
            <strong>{{ $order->guest_name }}</strong><br>
            Phone: {{ $order->guest_phone }}<br>
            @if ($order->guest_email) Email: {{ $order->guest_email }}<br> @endif
        </p>
    @endif

    <p>
        {{ $order->delivery_mode === 'delivery' ? 'Delivery' : 'Pickup' }} on
        <strong>{{ $order->scheduled_date?->format('d M Y') }}</strong> at {{ $order->scheduled_time }}<br>
        Payment: {{ $order->payment_method === 'cod' ? 'Cash' : 'Online ('.$order->payment_status.')' }}
    </p>

    @if ($order->delivery_mode === 'delivery' && $order->address)
        <p>
            Deliver to: {{ $order->address->address_line_1 }}@if ($order->address->address_line_2), {{ $order->address->address_line_2 }}@endif,
            {{ $order->address->city }}, {{ $order->address->state }} {{ $order->address->pincode }}
        </p>
    @endif

    <table style="width: 100%; border-collapse: collapse;">
        @foreach ($order->items as $item)
            <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #eee;">
                    {{ $item->product_name }}@if ($item->size_label) ({{ $item->size_label }})@endif × {{ $item->quantity }}
                </td>
                <td style="padding: 6px 0; border-bottom: 1px solid #eee; text-align: right;">₹{{ number_format($item->line_total, 0) }}</td>
            </tr>
        @endforeach
        @if ($order->delivery_fee > 0)
            <tr>
                <td style="padding: 6px 0;">Delivery</td>
                <td style="padding: 6px 0; text-align: right;">₹{{ number_format($order->delivery_fee, 0) }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding: 8px 0;"><strong>Total</strong></td>
            <td style="padding: 8px 0; text-align: right;"><strong>₹{{ number_format($order->total, 0) }}</strong></td>
        </tr>
    </table>

    @if ($order->notes)
        <p style="color: #666;">Notes: {{ $order->notes }}</p>
    @endif

    @unless ($forShop)
        <p style="color: #666;">We will contact you on {{ $order->guest_phone }} if we need anything. — Gharelu Bake</p>
    @endunless
</body>
</html>
