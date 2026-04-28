<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    // Relationship with Item model
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    // Scopes for querying by name and description
    public function scopeName(Builder $query, string $value): Builder
    {
        return $query->where('categories.name', 'LIKE', "%{$value}%");
    }

    public function scopeDescription(Builder $query, string $value): Builder
    {
        return $query->where('categories.description', 'LIKE', "%{$value}%");
    }

    /**
     * Custom delete method to check for associated items
     *
     * @return bool|null
     * @throws \Exception
     */
    public function delete(): bool|null
    {
        if ($this->items->isNotEmpty()) {
            throw new Exception('This Category still has associated Items.');
        }

        return parent::delete();
    }
}
