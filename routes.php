<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Paymenter\Extensions\Gateways\FreeKassa\FreeKassa;

Route::match(['get', 'post'], '/extensions/gateways/freekassa/webhook', [FreeKassa::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('gateways.freekassa.webhook');