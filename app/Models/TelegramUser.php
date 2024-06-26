<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'telegram_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'telegram_id',
        'first_name',
        'last_name',
        'username',
        'phone_number',
        'language_code',
        'is_premium',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_premium' => 'boolean',
    ];

    /**
     * Get the state record associated with the user.
     */
    public function state()
    {
        return $this->hasOne(TelegramUserState::class);
    }

    /**
     * Boot method for the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            $user->state()->create([
                'is_allowed' => false,
                'state' => 'initial',
            ]);
        });
    }
}
