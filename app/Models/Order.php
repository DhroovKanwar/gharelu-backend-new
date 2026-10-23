<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'invoice_number',
        'user_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_phone_country_code',
        'delivery_mode',
        'pickup_type',
        'address_id',
        'scheduled_date',
        'scheduled_time',
        'estimated_delivery_date',
        'subtotal',
        'delivery_fee',
        'total',
        'currency',
        'payment_method',
        'payment_status',
        'order_status',
        'idempotency_key',
        'notes',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'estimated_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * The sender's phone is an Indian number (+91; also true for orders from before country codes existed).
     * SMS and WhatsApp to customers only work for Indian numbers.
     */
    public function senderIsIndian(): bool
    {
        return ($this->guest_phone_country_code ?? '+91') === '+91';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentAdjustments(): HasMany
    {
        return $this->hasMany(OrderPaymentAdjustment::class)->latest('id');
    }
}
