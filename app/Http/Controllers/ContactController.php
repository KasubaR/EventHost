<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'subject' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        // TODO: send notification email or store in DB when ready
        // Mail::to(config('mail.support_address'))->send(new ContactFormMail($request->validated()));

        return redirect()->route('contact')->with('success', true);
    }
}
