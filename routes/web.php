<?php

use App\Http\Controllers\HealthStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthStatusController::class);

Route::fallback(function () {
    return view('sarcasm');
});
