<?php

namespace App\JsonApi\V2;

// use App\JsonApi\V2\Categories\CategorySchema;
// use App\JsonApi\V2\Items\ItemSchema;
// use App\JsonApi\V2\Permissions\PermissionSchema;
use App\JsonApi\V2\Roles\RoleSchema;
// use App\JsonApi\V2\Tags\TagSchema;
// use App\JsonApi\V2\Users\UserSchema;
use LaravelJsonApi\Core\Document\JsonApi;
use LaravelJsonApi\Core\Server\Server as BaseServer;

class Server extends BaseServer
{
    /**
     * The base URI namespace for this server.
     */
    protected string $baseUri = '/api/v2';

    /**
     * Bootstrap the server when it is handling an HTTP request.
     */
    public function serving(): void
    {
        // Add any initialization logic if needed.
    }

    /**
     * Get the server's list of schemas.
     */
    protected function allSchemas(): array
    {
        return [
            // CategorySchema::class,
            // ItemSchema::class,
            // PermissionSchema::class,
            RoleSchema::class,
            // TagSchema::class,
            // UserSchema::class,
            // You can add other schemas here as you port them over.
        ];
    }

    public function jsonApi(): JsonApi
    {
        return JsonApi::make('2.0');
    }
}
