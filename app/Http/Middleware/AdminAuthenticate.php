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

        return $next($request);
    }

    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('admin.login');
    }
}
