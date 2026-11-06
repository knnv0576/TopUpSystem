<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointGrant extends Model
{
    protected $guarded = [];

    protected $casts = ['points' => 'integer', 'granted_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function idempotencyKey(): string
    {
        return "grant:order:{$this->order_id}";
    }
}
