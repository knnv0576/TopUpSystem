<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = ['points' => 'integer'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'initiator_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'supervisor_id');
    }
}
