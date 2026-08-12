<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomCakeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'occasion',
        'other_occasion',
        'occasion_date',
        'preferred_time',
        'custom_time',
        'people_count',
        'delivery_type',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'pincode',
        'landmark',
        'flavour',
        'shape',
        'theme',
        'cake_message',
        'eggless',
        'budget',
        'notes',
        'reference_image_path',
        'status',
    ];

    protected $casts = [
        'occasion_date' => 'date',
        'people_count' => 'integer',
        'eggless' => 'boolean',
    ];
}
