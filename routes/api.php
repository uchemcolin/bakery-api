<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [OidcController::class, 'user']);
    Route::post('/logout', [OidcController::class, 'logout']);
});