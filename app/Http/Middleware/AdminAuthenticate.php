<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class AdminAuthenticate extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (! $this->auth->guard('admin')->check()) {
            $this->unauthenticated($request, ['admin']);
        }

        // Set the user on the web guard (in-memory only) so Spatie can resolve
        // permissions using guard_name='web' without shouldUse() corrupting
        // config('auth.defaults.guard').
        $this->auth->guard('web')->setUser($this->auth->guard('admin')->user());

        return $next($request);
    }

    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('admin.login');
    }
}
