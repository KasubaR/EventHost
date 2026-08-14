<?php

namespace App\Http\Controllers;

use App\Notifications\ContactMessageNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContactController extends Controller
{
    /**
     * Topics offered by the select on the contact page.
     *
     * @var list<string>
     */
    private const SUBJECTS = [
        'General enquiry',
        'Technical support',
        'Billing & payments',
        'Feature request',
        'Partnership',
        'Other',
    ];

    public function show(): View
    {
        return view('contact');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'subject' => ['required', 'string', Rule::in(self::SUBJECTS)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        // Logged before sending so a submission is never lost to a mail failure.
        Log::info('contact.submitted', [
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'ip' => $request->ip(),
        ]);

        try {
            Notification::route('mail', config('mail.support_address'))
                ->notify(new ContactMessageNotification(
                    $data['name'],
                    $data['email'],
                    $data['subject'],
                    $data['message'],
                ));
        } catch (\Throwable $e) {
            Log::error('contact.delivery_failed', [
                'email' => $data['email'],
                'exception' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('contact_error', true);
        }

        return redirect()->route('contact')->with('success', true);
    }
}
