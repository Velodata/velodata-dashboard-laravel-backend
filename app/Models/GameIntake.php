<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameIntake extends Model
{
    use HasFactory;

    protected $table = 'game_intakes';

    protected $fillable = [
        'code',
        'name',
        'trainer_user_id',
        'status',
        'active_week',
        'starts_at',
        'ends_at',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function trainer()
    {
        return $this->belongsTo(User::class, 'trainer_user_id');
    }

    public function gameUsers()
    {
        return $this->hasMany(GameUser::class, 'intake_id');
    }

    public function baselines()
    {
        return $this->hasMany(GameBaseline::class, 'intake_id');
    }

    public function activeBaseline()
    {
        return $this->hasOne(GameBaseline::class, 'intake_id')->where('is_active', true);
    }
}
