<?php

// namespace App\Providers;

// use Illuminate\Support\ServiceProvider;
// // IJV - 2024.08.18 - next two lines of code added to enable SQL logging.
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;

// class AppServiceProvider extends ServiceProvider
// {
//     /**
//      * Register any application services.
//      *
//      * @return void
//      */
//     public function register()
//     {
//         //
//     }

//     /**
//      * Bootstrap any application services.
//      *
//      * @return void
//      */
//     public function boot()
//     {
//         // IJV - 2024.08.18 - next six lines of code added to enable SQL logging.
//         DB::listen(function ($query) {
//         	Log::info(
// 	            $query->sql,
// 	            $query->bindings,
// 	            $query->time
// 	        );
// 	    });
//     }
// }

//IJV - 2024.10.26 I brought across a virgin version of this file to use while solving the 401 permissions problem.
// <?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
