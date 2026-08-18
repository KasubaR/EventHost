<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RsvpController extends Controller
{
    public function index(Request $request, Event $event): View
    {
        $event->load(['user:id,name,email']);

        $search = trim((string) $request->query('q', ''));

        $rsvps = $event->rsvps()
            ->with('guest:id,name,email')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('guest', function ($gq) use ($search): void {
                    $gq->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.rsvps.index', [
            'adminEvent' => $event,
            'rsvps' => $rsvps,
            'search' => $search,
        ]);
    }
}
