<?php

namespace App\DemoGame\Models;

use App\Support\LocksOutOnFailures;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GamePlayer extends Authenticatable
{
    use LocksOutOnFailures;

    protected $table = 'game_players';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'suspended_at' => 'datetime',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
    ];

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'player_id');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** 密碼的指紋，存在 session 裡。密碼改變後，用舊密碼登入的 session 會全部失效。 */
    public function sessionFingerprint(): string
    {
        return hash_hmac('sha256', (string) $this->password, (string) config('app.key'));
    }

    public function fingerprintSessionKey(): string
    {
        return 'game_credential_fingerprint_'.$this->getKey();
    }

    /** 建立玩家與錢包，玩家編號隨機產生，碰撞時重試。 */
    public static function register(string $username, string $password, string $nickname): self
    {
        return DB::transaction(function () use ($username, $password, $nickname) {
            do {
                $playerNo = 'P'.Str::upper(Str::random(8));
                $playerNo = strtr($playerNo, ['O' => 'X', 'I' => 'Y', 'L' => 'Z']);
            } while (self::where('player_no', $playerNo)->exists());

            $player = self::create([
                'username' => $username,
                'password' => $password,
                'nickname' => $nickname,
                'player_no' => $playerNo,
            ]);
            $player->wallet()->create(['balance' => 0, 'frozen' => 0]);

            return $player;
        });
    }
}
