<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TestUser extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users_webdev'; // Optional, if your table name is 'users'

    protected $fillable = [
        'name',
        'email',
        'profile_image',
        'role_id', // Allow mass assignment for role_id
        // 'role_name', // This may not be needed if you're not directly storing it
    ];

    protected $hidden = [
        'password', // If you have a password field
        'remember_token', // Optional, if using remember me functionality
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        // Add any other date fields here
    ];

    // Relationships

    // A user can have multiple roles
    public function roles()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    // Optionally, if there are other relationships, you can define them here
}
