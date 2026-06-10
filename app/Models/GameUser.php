<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameUser extends Model
{
    use HasFactory;

    protected $table = 'game_users';

    protected $fillable = [
        'intake_id',
        'first_name',
        'surname',
        'preferred_name',
        'display_name',
        'email',
        'created_by_email',
        'profile_image',
        'password',
        'must_change_password',
        'last_login_at',
        'phone_no',
        'gender',
        'location',
        'languages',
        'city',
        'state',
        'postcode',
        'updated_by',
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
    ];

    protected $casts = [
        'is_spy' => 'boolean',
        'is_protector' => 'boolean',
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
        'languages' => 'array',
        'action_locked_until' => 'datetime',
        'eliminated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function intake()
    {
        return $this->belongsTo(GameIntake::class, 'intake_id');
    }

    public function actionLockedBy()
    {
        return $this->belongsTo(GameUser::class, 'action_locked_by_game_user_id');
    }

    public function eliminatedBy()
    {
        return $this->belongsTo(GameUser::class, 'eliminated_by_game_user_id');
    }
}
