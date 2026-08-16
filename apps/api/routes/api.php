<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'MarQira Pulse API',
        'timestamp' => now()->toIso8601String(),
    ]);
});
