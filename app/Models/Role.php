<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'name',
        'guard_name',
    ];

    // Each role can be assigned to only one user
    public function user()
    {
        return $this->hasOne(User::class);
    }



    public function permissions()
    {
        return $this->belongsToMany(\App\Models\Permission::class, 'role_has_permissions', 'role_id', 'permission_id');
    }
    


}
