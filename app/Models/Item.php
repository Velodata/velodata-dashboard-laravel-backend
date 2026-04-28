<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;


class Item extends Model
{
    protected $fillable = [
        'name',
        'excerpt',
        'description',
        'status',
        'image',
        'is_on_homepage',
        'date_at',
        'created_at',
        'updated_at',
        'user_id',
        'category_id',
        'tag_id', // Ensure this is fillable
    ];

    // Relationship with Category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with Tag
    public function tag()
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
