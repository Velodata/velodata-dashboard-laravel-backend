<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    // Specify the table name if it's not the plural form of the model name
    protected $table = 'permissions'; // Only needed if your table name doesn't follow Laravel's naming conventions

    // Specify which attributes are mass assignable
    protected $fillable = [
        'name',
        'guard_name',
    ];

    // Define any relationships (if applicable)
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions', 'permission_id', 'role_id');
    }
}
