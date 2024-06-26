<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramUserState extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'telegram_user_states';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'telegram_user_id',
        'is_allowed',
        'state',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_allowed' => 'boolean',
    ];

    /**
     * Get the user that owns the state.
     */
    public function telegramUser()
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function getCurrentState()
    {
        if ($this->state) {
            $states = explode(':', $this->state);
            return end($states);
        }

        return 'start';
    }

    public function addState($newState)
    {
        if ($this->state) {
            $this->state .= ":$newState";
        } else {
            $this->state = $newState;
        }

        $this->save();
    }

    public function removeLastState()
    {
        if ($this->state) {
            $states = explode(':', $this->state);
            array_pop($states);
            $this->state = implode(':', $states);
            $this->save();
        }
    }
}
