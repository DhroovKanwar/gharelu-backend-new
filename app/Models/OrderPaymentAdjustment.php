<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'admin_id',
        'from_status',
        'to_status',
        'razorpay_payment_id',
        'remarks',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
