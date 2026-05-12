<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected static function booted()
    {
        static::created(function ($user) {
            $user->ensureCustno();
        });
    }

    public function ensureCustno()
    {
        if (empty($this->custno)) {
            $this->custno = $this->id + 100000;
            $this->saveQuietly();
        }

        return $this->custno;
    }

    protected $table = 'users'; // Optional, if your table name is 'users'

    protected $fillable = [
        'name',
        'email',
        'profile_image',
        'role_id', // Allow mass assignment for role_id
        'is_system_user',
        'is_game_user',
        'home_intake_id',
        'action_locked_until',
        'action_locked_reason',
        'action_locked_by_user_id',
        // 'role_name', // This may not be needed if you're not directly storing it
    ];

    protected $hidden = [
        'password', // If you have a password field
        'remember_token', // Optional, if using remember me functionality
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'action_locked_until',
        // Add any other date fields here
    ];

    protected $casts = [
        'is_system_user' => 'boolean',
        'is_game_user' => 'boolean',
        'action_locked_until' => 'datetime',
    ];

    // Relationships

    // A user can have multiple roles
    public function roles()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function homeIntake()
    {
        return $this->belongsTo(GameIntake::class, 'home_intake_id');
    }

    public function actionLockedBy()
    {
        return $this->belongsTo(User::class, 'action_locked_by_user_id');
    }

    public function actionLockedUsers()
    {
        return $this->hasMany(User::class, 'action_locked_by_user_id');
    }
}
