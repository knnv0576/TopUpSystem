<?php

namespace App\DemoGame\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $table = 'game_wallets';

    protected $primaryKey = 'player_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['balance' => 'integer', 'frozen' => 'integer'];

    public function available(): int
    {
        return $this->balance - $this->frozen;
    }
}
