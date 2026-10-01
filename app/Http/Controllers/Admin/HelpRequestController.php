<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminHelpRequest;
use App\Services\HelpRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The admin side of client help requests — the queue, and claim / complete / decline.
 * Permission `users.act_as`, same as acting as the client. Plan: plans/admin-create-events.md.
 */
class HelpRequestController extends Controller
{
    public function index(Request $request): View
    {
        AdminHelpRequest::expireStale();

        $status = $request->query('status');
        $status = is_string($status) ? HelpRequestStatus::tryFrom($status) : null;

        $requests = AdminHelpRequest::query()
            ->with(['user:id,name,email', 'event:id,name', 'assignedAdmin:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                HelpRequestStatus::Open->value,
                HelpRequestStatus::InProgress->value,
            ])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.help-requests.index', [
            'requests' => $requests,
            'filterStatus' => $status?->value ?? '',
            'statuses' => HelpRequestStatus::cases(),
        ]);
    }

    public function show(AdminHelpRequest $helpRequest): View
    {
        AdminHelpRequest::expireStale();
        $helpRequest->refresh()->load(['user', 'event', 'assignedAdmin']);

        $admin = Auth::guard('admin')->user();

        return view('admin.help-requests.show', [
            'helpRequest' => $helpRequest,
            'canAct' => $helpRequest->grantsAccess() && $helpRequest->assigned_admin_id === $admin->id,
        ]);
    }

    public function claim(AdminHelpRequest $helpRequest, HelpRequestService $service): RedirectResponse
    {
        $claimed = $service->claim($helpRequest, Auth::guard('admin')->user());

        return back()->with($claimed ? 'status' : 'error', $claimed
            ? 'Claimed. The client has been emailed and you can now act on their account.'
            : 'That request is no longer open.');
    }

    public function complete(AdminHelpRequest $helpRequest, HelpRequestService $service): RedirectResponse
    {
        $this->authorizeAssignee($helpRequest);

        $done = $service->complete($helpRequest);

        return back()->with($done ? 'status' : 'error', $done ? 'Marked as completed.' : 'That request is not in progress.');
    }

    public function decline(Request $request, AdminHelpRequest $helpRequest, HelpRequestService $service): RedirectResponse
    {
        $data = $request->validate([
            'decline_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $declined = $service->decline($helpRequest, $data['decline_note'] ?? null);

        return back()->with($declined ? 'status' : 'error', $declined ? 'Declined. The client has been emailed.' : 'That request is already closed.');
    }

    /**
     * Only the admin who claimed it (or a super admin) closes it out.
     */
    private function authorizeAssignee(AdminHelpRequest $helpRequest): void
    {
        $admin = Auth::guard('admin')->user();

        abort_unless($helpRequest->assigned_admin_id === $admin->id || $admin->hasRole('super_admin'), 403);
    }
}
