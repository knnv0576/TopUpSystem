<?php

namespace App\DemoGame\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedPacket extends Model
{
    protected $table = 'game_red_packets';

    protected $guarded = [];

    protected $casts = ['points' => 'integer', 'expires_at' => 'datetime', 'closed_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'recipient_id');
    }
}
