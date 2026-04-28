<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tasks'; // Optional, but adds clarity

	protected $fillable = [
	    'title',
	    'description',
	    'due_date',
	    'completed',
	    'user_email'
	];


    protected $casts = [
        'completed' => 'boolean',
        'due_date' => 'date',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'due_date',
    ];

    // Optional: Add relationships here in the future (e.g., assigned user)
}
