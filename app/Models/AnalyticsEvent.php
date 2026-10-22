<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['visitor_id', 'type', 'label', 'path', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
