<?php

use App\Http\Controllers\AstragateWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Astragate routes (card payments)
|--------------------------------------------------------------------------
| Loaded from bootstrap/app.php inside the `web` group. Astragate does not sign
| callbacks, so the secret in the URL is the credential — see config/astragate.php.
*/

Route::post('/webhooks/astragate/{secret}', AstragateWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('webhooks.astragate');
