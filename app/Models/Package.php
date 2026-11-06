<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'integer', 'active' => 'boolean'];

    public function points(): int
    {
        return $this->price * config('topup.points_per_twd');
    }
}
