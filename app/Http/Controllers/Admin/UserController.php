<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminUserStatusRequest;
use App\Models\Admin;
use App\Models\User;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->withCount('events')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function show(User $user): View
    {
        $user->loadCount('events');
        $user->load([
            'events' => fn ($q) => $q->orderByDesc('created_at')->limit(10),
        ]);

        return view('admin.users.show', [
            'adminUser' => $user,
        ]);
    }

    public function updateStatus(UpdateAdminUserStatusRequest $request, User $user): RedirectResponse
    {
        if ($this->adminActsOnLinkedCustomerAccount($request, $user)) {
            return redirect()->back()->withErrors(['status' => 'You cannot change your own account status here.']);
        }

        $validated = $request->validated();
        $user->status = $validated['status'];
        $user->save();

        AdminActivity::log('Admin changed user status', [
            'target_user_id' => $user->id,
            'status' => $user->status,
        ]);

        return redirect()->back()->with('status', 'user-status-updated');
    }

    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        Password::broker()->sendResetLink(['email' => $user->email]);

        AdminActivity::log('Admin sent password reset email', [
            'target_user_id' => $user->id,
        ]);

        return redirect()->back()->with('status', 'password-reset-sent');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($this->adminActsOnLinkedCustomerAccount($request, $user)) {
            return redirect()->route('admin.users.index')->withErrors(['delete' => 'You cannot delete your own account.']);
        }

        AdminActivity::log('Admin deleted user', [
            'target_user_id' => $user->id,
            'email' => $user->email,
        ]);

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'user-deleted');
    }

    private function adminActsOnLinkedCustomerAccount(Request $request, User $user): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin
            && $admin->user_id !== null
            && (int) $admin->user_id === (int) $user->id;
    }
}
