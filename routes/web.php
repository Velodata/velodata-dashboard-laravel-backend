<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\SseController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sse-test', function () {
    return response()->stream(function () {
        for ($i = 1; $i <= 5; $i++) {
            echo "data: " . json_encode(['tick' => $i, 'time' => now()]) . "\n\n";
            ob_flush();
            flush();
            sleep(1);
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
    ]);
});

Route::get('/sse-profile-updates', [SseController::class, 'profileUpdates']);
