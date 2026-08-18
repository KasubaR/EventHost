<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminUserStatusRequest;
use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\EventCreditService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
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

        $creditHistory = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->with(['event:id,name', 'payment:id,plan_key'])
            ->newestFirst()
            ->limit(25)
            ->get();

        return view('admin.users.show', [
            'adminUser' => $user,
            'creditHistory' => $creditHistory,
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

    public function addCredits(Request $request, User $user, EventCreditService $credits): RedirectResponse
    {
        $validated = $request->validate([
            'credits' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $credits->grant(
            $user,
            (int) $validated['credits'],
            CreditTransaction::REASON_ADMIN_GRANT,
            note: 'Granted by '.(auth('admin')->user()?->email ?? 'an admin')
        );

        AdminActivity::log('Admin added event credits', [
            'target_user_id' => $user->id,
            'credits_added' => $validated['credits'],
            'new_total' => $user->fresh()->event_credits,
        ]);

        return redirect()->back()->with('status', 'Credits added successfully.');
    }

    /**
     * Enterprise is a Contact Sales tier — there is no self-checkout plan for
     * it in config('billing.plans'), so this is the only way a customer who
     * bought a custom package off-platform ends up with the tier on their
     * account. Any tier is assignable here, including downgrading someone
     * back off Enterprise once a custom engagement ends.
     */
    public function updateTier(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'subscription_tier' => ['required', Rule::enum(SubscriptionTier::class)],
        ]);

        $tier = SubscriptionTier::from($validated['subscription_tier']);
        $user->subscription_tier = $tier;
        $user->save();

        AdminActivity::log('Admin changed user subscription tier', [
            'target_user_id' => $user->id,
            'tier' => $tier->value,
        ]);

        return redirect()->back()->with('status', 'Subscription tier updated successfully.');
    }

    private function adminActsOnLinkedCustomerAccount(Request $request, User $user): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin
            && $admin->user_id !== null
            && (int) $admin->user_id === (int) $user->id;
    }
}
