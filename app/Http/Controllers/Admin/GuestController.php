<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestController extends Controller
{
    public function index(Request $request, Event $event): View
    {
        $event->load(['user:id,name,email']);

        $search = trim((string) $request->query('q', ''));

        $guests = $event->guests()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.guests.index', [
            'adminEvent' => $event,
            'guests' => $guests,
            'search' => $search,
        ]);
    }
}
