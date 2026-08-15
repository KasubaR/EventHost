<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Review;
use App\Support\SafeIntendedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->only('destroy');
    }

    public function create(): View
    {
        // Same featured set as the homepage strip; the sidebar only has room
        // for one quote. Hidden when nothing qualifies — see login.blade.php.
        $featuredReview = Review::query()
            ->featuredForHomepage()
            ->first();

        return view('auth.login', compact('featuredReview'));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        DB::table('users')->where('id', $request->user()->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        return SafeIntendedUrl::redirect($request, route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
