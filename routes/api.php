<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OIDC token exchange
|--------------------------------------------------------------------------
|
| This endpoint receives the short-lived one-time code generated
| during the OIDC callback and returns the Sanctum personal access token.
|
| It must NOT use auth:sanctum because the caller does not have
| the Sanctum token yet.
|
*/

Route::post('/oauth/exchange', [OidcController::class, 'exchangeToken']);

/*
|--------------------------------------------------------------------------
| Authenticated API routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [OidcController::class, 'user']);

    Route::post('/logout', [OidcController::class, 'logout']);
});
