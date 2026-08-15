<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    /**
     * The password change form only. The update itself stays on
     * Auth\PasswordController (PUT /password, routes/auth.php) — it returns
     * back(), which lands here.
     */
    public function edit(Request $request): View
    {
        return view('settings.security', [
            'user' => $request->user(),
        ]);
    }
}
