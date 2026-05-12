<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTableBaselineRow extends Model
{
    use HasFactory;

    protected $table = 'user_table_baseline_rows';

    protected $fillable = [
        'baseline_id',
        'user_id',
        'name',
        'email',
        'password',
        'profile_image',
        'google_id',
        'custno',
        'role_id',
        'role_name',
        'status',
        'updated_by',
        'company_name',
        'gender',
        'location',
        'phone_no',
        'address_1',
        'address_2',
        'address_3',
        'city',
        'state',
        'postcode',
        'is_system_user',
        'is_game_user',
        'home_intake_id',
        'action_locked_until',
        'action_locked_reason',
        'action_locked_by_user_id',
        'snapshot',
    ];

    protected $casts = [
        'is_system_user' => 'boolean',
        'is_game_user' => 'boolean',
        'action_locked_until' => 'datetime',
        'snapshot' => 'array',
    ];

    protected $hidden = [
        'password',
    ];

    public function baseline()
    {
        return $this->belongsTo(UserTableBaseline::class, 'baseline_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
