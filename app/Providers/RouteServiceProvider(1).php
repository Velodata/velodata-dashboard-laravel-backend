<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
// IJV - 2024.10.26 - the next commented section was an effort to solve the 401 errors.
//     public function boot()
//     {
//         $this->configureRateLimiting();

//         $this->routes(function () {
//             Route::middleware('api')
//                 ->prefix('api')
//                 ->group(base_path('routes/api.php'));

//             Route::middleware('web')
//                 ->group(base_path('routes/web.php'));
//         });
//     }
	public function boot()
	{
	    $this->configureRateLimiting();
	
	    $this->routes(function () {
	        Route::middleware([]) // Remove the 'api' middleware for API routes
	            ->prefix('api')
	            ->group(base_path('routes/api.php'));
	
	        Route::middleware('web')
	            ->group(base_path('routes/web.php'));
	    });
	}
    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
