<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OidcController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sso/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
Route::get('/sso/callback', [OidcController::class, 'callback'])->name('oidc.callback');
