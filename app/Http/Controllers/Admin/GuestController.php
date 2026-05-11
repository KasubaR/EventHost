<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $guests = Guest::query()
            ->with(['event:id,name,user_id', 'event.user:id,name,email'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhereHas('event', function ($eq) use ($search): void {
                            $eq->where('name', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.guests.index', [
            'guests' => $guests,
            'search' => $search,
        ]);
    }
}
