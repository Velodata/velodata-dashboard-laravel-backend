<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameBaselineUser extends Model
{
    use HasFactory;

    protected $table = 'game_baseline_users';

    protected $fillable = [
        'baseline_id',
        'game_user_id',
        'first_name',
        'surname',
        'preferred_name',
        'display_name',
        'email',
        'special_needs',
        'game_role',
        'game_status',
        'is_spy',
        'is_protector',
        'action_locked_until',
        'action_locked_reason',
        'action_locked_by_game_user_id',
        'eliminated_at',
        'eliminated_by_game_user_id',
        'metadata',
        'snapshot',
    ];

    protected $casts = [
        'is_spy' => 'boolean',
        'is_protector' => 'boolean',
        'action_locked_until' => 'datetime',
        'eliminated_at' => 'datetime',
        'metadata' => 'array',
        'snapshot' => 'array',
    ];

    public function baseline()
    {
        return $this->belongsTo(GameBaseline::class, 'baseline_id');
    }

    public function gameUser()
    {
        return $this->belongsTo(GameUser::class, 'game_user_id');
    }
}
