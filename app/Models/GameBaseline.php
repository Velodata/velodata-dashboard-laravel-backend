<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameBaseline extends Model
{
    use HasFactory;

    protected $table = 'game_baselines';

    protected $fillable = [
        'intake_id',
        'name',
        'description',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function intake()
    {
        return $this->belongsTo(GameIntake::class, 'intake_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function baselineUsers()
    {
        return $this->hasMany(GameBaselineUser::class, 'baseline_id');
    }
}
