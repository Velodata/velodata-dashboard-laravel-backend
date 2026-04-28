<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'color',
    ];

    // Relationship with Item model
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class);
    }

    // Scopes for querying by name and color
    public function scopeName(Builder $query, string $value): Builder
    {
        return $query->where('tags.name', 'LIKE', "%{$value}%");
    }

    public function scopeColor(Builder $query, string $value): Builder
    {
        return $query->where('tags.color', 'LIKE', "%{$value}%");
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
            throw new Exception('This Tag still has associated Items.');
        }

        return parent::delete();
    }
}
