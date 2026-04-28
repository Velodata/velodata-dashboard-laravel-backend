<?php

namespace App\JsonApi\V2\Role;

use LaravelJsonApi\Eloquent\Schema;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Fields\DateTime;

class RoleSchema extends Schema
{
    public static string $model = \App\Models\Role::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('name'),
            DateTime::make('created_at')->sortable(),
            DateTime::make('updated_at')->sortable(),
        ];
    }
}
