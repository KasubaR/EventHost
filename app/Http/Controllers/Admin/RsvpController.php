<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rsvp;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RsvpController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $rsvps = Rsvp::query()
            ->with([
                'guest:id,name,email',
                'event:id,name,user_id',
                'event.user:id,name,email',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->whereHas('guest', function ($gq) use ($search): void {
                        $gq->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    })->orWhereHas('event', function ($eq) use ($search): void {
                        $eq->where('name', 'like', '%'.$search.'%');
                    });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.rsvps.index', [
            'rsvps' => $rsvps,
            'search' => $search,
        ]);
    }
}
