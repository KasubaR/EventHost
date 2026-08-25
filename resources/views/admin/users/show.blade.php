<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $u = $adminUser;
    @endphp

    <x-slot name="title">{{ $u->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.users.index') }}">Users</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $u->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $u->name }}</h1>
                <p class="dph-sub">{{ $u->email }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('admin.users.index') }}" class="evt-btn-outline dash-header-cta">Back to list</a>
                @if(auth('admin')->user()?->can('ticketing.approve') && $u->status !== 'suspended')
                    <a href="{{ route('admin.ticketing.create', ['user' => $u->id]) }}" class="btn-primary dash-header-cta">
                        <i class="fa-solid fa-ticket"></i> Create ticketed event
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'custom-quote-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Custom quote sent. The customer was emailed and will see it on Billing and their dashboard.</div>
    @elseif (session('status') === 'custom-quote-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Custom quote updated. The customer was emailed again.</div>
    @elseif (session('status') === 'custom-quote-cancelled')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Custom quote cancelled.</div>
    @elseif (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Account</h2>
            <p class="admin-muted admin-mt-sm">Status: {{ $u->status }} · Events: {{ $u->events_count }} · Credits: {{ $u->event_credits }} · Phone: {{ $u->phone ?? '—' }}</p>
            <p class="admin-muted">Registered {{ $u->created_at->format('M j, Y g:i a') }}</p>

            @if(auth('admin')->user()?->can('users.manage_status'))
                <div class="admin-mt-md">
                    <h3 style="font-size:14px;font-weight:600;margin-bottom:8px;">Event Credits</h3>
                    <p class="admin-muted" style="margin-bottom:10px;">Current balance: <strong>{{ $u->event_credits }}</strong></p>
                    <form method="post" action="{{ route('admin.users.add-credits', $u) }}" class="profile-form" style="display:flex;gap:8px;align-items:flex-end;">
                        @csrf
                        <div style="flex:1;">
                            <label for="credits-input" style="font-size:13px;">Add credits</label>
                            <input id="credits-input" type="number" name="credits" min="1" max="100" value="1" class="profile-input" style="margin-top:4px;">
                        </div>
                        <button type="submit" class="btn-primary" style="white-space:nowrap;">Add credits</button>
                    </form>
                </div>
            @endif

            @if(auth('admin')->user()?->can('users.manage_status'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.status', $u) }}" class="profile-form admin-mt-md">
                        @csrf
                        @method('PATCH')
                        <label for="user-status">Moderation status</label>
                        <select id="user-status" name="status" class="profile-input">
                            <option value="active" @selected($u->status === 'active')>Active</option>
                            <option value="suspended" @selected($u->status === 'suspended')>Suspended</option>
                        </select>
                        <div class="admin-actions admin-mt-md">
                            <button type="submit" class="btn-primary">Update status</button>
                        </div>
                    </form>
                @else
                    <p class="admin-muted admin-mt-md">You cannot change your own status here.</p>
                @endif

                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.update-email', $u) }}" class="profile-form admin-mt-md">
                        @csrf
                        @method('PATCH')
                        <label for="user-email">Account email</label>
                        <input id="user-email" type="email" name="email" value="{{ old('email', $u->email) }}" class="profile-input">
                        <p class="admin-muted" style="margin-top:6px;">Use this if the user lost access to their inbox — password reset and re-verification both go to whatever address is on file. The old address is notified, and the new one has to be re-verified.</p>
                        <div class="admin-actions admin-mt-md">
                            <button type="submit" class="btn-primary">Update email</button>
                        </div>
                    </form>
                @endif

                <form method="post" action="{{ route('admin.users.update-tier', $u) }}" class="profile-form admin-mt-md">
                    @csrf
                    @method('PATCH')
                    <label for="user-tier">Subscription tier</label>
                    <select id="user-tier" name="subscription_tier" class="profile-input">
                        @foreach (\App\Enums\SubscriptionTier::cases() as $tierOption)
                            <option value="{{ $tierOption->value }}" @selected($u->subscriptionTier() === $tierOption)>{{ $tierOption->label() }}</option>
                        @endforeach
                    </select>
                    <p class="admin-muted" style="margin-top:6px;">Enterprise can also be unlocked when the customer pays a custom Lenco quote below.</p>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Update tier</button>
                    </div>
                </form>

                <div class="admin-mt-md">
                    <h3 style="font-size:14px;font-weight:600;margin-bottom:8px;">Custom Enterprise quote</h3>
                    <p class="admin-muted" style="margin-bottom:10px;">
                        After a Contact Sales deal, set the amount this user should pay. It appears only on their billing Custom card — never on the public homepage.
                    </p>

                    @if ($pendingCustomQuote)
                        <p class="admin-muted" style="margin-bottom:10px;">
                            Current pending quote:
                            <strong>{{ $pendingCustomQuote->formattedAmount() }}</strong>
                            · {{ $pendingCustomQuote->credits_granted }} credit{{ $pendingCustomQuote->credits_granted === 1 ? '' : 's' }}
                            @if ($pendingCustomQuote->note)
                                · {{ $pendingCustomQuote->note }}
                            @endif
                        </p>
                        <form method="post" action="{{ route('admin.users.custom-quote.update', [$u, $pendingCustomQuote]) }}" class="profile-form">
                            @csrf
                            @method('PATCH')
                            <label for="quote-amount">Amount (ZMW)</label>
                            <input id="quote-amount" type="number" name="amount" min="0.01" step="0.01" required
                                   class="profile-input" value="{{ old('amount', $pendingCustomQuote->amount) }}">
                            @error('amount')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <label for="quote-credits" class="admin-mt-sm">Credits included</label>
                            <input id="quote-credits" type="number" name="credits_granted" min="1" max="100" required
                                   class="profile-input" value="{{ old('credits_granted', $pendingCustomQuote->credits_granted) }}">
                            @error('credits_granted')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <label for="quote-note" class="admin-mt-sm">Note <span class="profile-optional">optional</span></label>
                            <input id="quote-note" type="text" name="note" maxlength="500"
                                   class="profile-input" value="{{ old('note', $pendingCustomQuote->note) }}">
                            @error('note')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <div class="admin-actions admin-mt-md">
                                <button type="submit" class="btn-primary">Update quote</button>
                            </div>
                        </form>
                        <form method="post" action="{{ route('admin.users.custom-quote.destroy', [$u, $pendingCustomQuote]) }}" class="profile-form admin-mt-sm" data-confirm="Cancel this pending quote?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Cancel quote</button>
                        </form>
                    @else
                        <form method="post" action="{{ route('admin.users.custom-quote.store', $u) }}" class="profile-form">
                            @csrf
                            <label for="quote-amount">Amount (ZMW)</label>
                            <input id="quote-amount" type="number" name="amount" min="0.01" step="0.01" required
                                   class="profile-input" value="{{ old('amount') }}" placeholder="12500">
                            @error('amount')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <label for="quote-credits" class="admin-mt-sm">Credits included</label>
                            <input id="quote-credits" type="number" name="credits_granted" min="1" max="100" required
                                   class="profile-input" value="{{ old('credits_granted', 1) }}">
                            @error('credits_granted')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <label for="quote-note" class="admin-mt-sm">Note <span class="profile-optional">optional</span></label>
                            <input id="quote-note" type="text" name="note" maxlength="500"
                                   class="profile-input" value="{{ old('note') }}" placeholder="Custom wedding site + templates">
                            @error('note')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            @error('custom_quote')
                                <p class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
                            @enderror
                            <div class="admin-actions admin-mt-md">
                                <button type="submit" class="btn-primary">Send quote to customer</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            @if(auth('admin')->user()?->can('users.password_reset'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.password-reset', $u) }}" class="profile-form admin-mt-md">
                        @csrf
                        <button type="submit" class="evt-btn-outline">Send password reset email</button>
                    </form>
                @endif
            @endif

            @if(auth('admin')->user()?->can('users.delete'))
                @if (! auth('admin')->user()->is($u))
                    <form method="post" action="{{ route('admin.users.destroy', $u) }}" class="profile-form admin-mt-md" data-confirm="Delete this user and all owned data?">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="evt-btn-outline evt-btn-danger-outline">Delete user</button>
                    </form>
                @endif
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Recent events</h2>
            <div class="admin-table-wrap admin-mt-sm">
                <table class="admin-table">
                    <thead>
                    <tr><th>Name</th><th>Type</th><th>Published</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($u->events as $event)
                        <tr>
                            <td>{{ $event->name }}</td>
                            <td>{{ $event->event_type }}</td>
                            <td>{{ $event->is_published ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-muted">No events.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if(auth('admin')->user()?->can('events.view'))
                <p class="admin-mt-sm">
                    <a href="{{ route('admin.events.index', ['q' => $u->email]) }}" class="evt-btn-outline">
                        View events for this owner <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </p>
            @endif
        </div>

        <div class="admin-panel-card">
            <h2>Credit history</h2>
            <p class="admin-muted admin-mt-sm">
                Every movement of this user's event credits, newest first. Balance is
                <strong>{{ $u->event_credits }}</strong>.
            </p>
            <div class="admin-table-wrap admin-mt-sm">
                <table class="admin-table">
                    <thead>
                    <tr><th>When</th><th>Reason</th><th>Change</th><th>Balance</th><th>For</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($creditHistory as $entry)
                        <tr>
                            <td>{{ $entry->created_at?->format('M j, Y H:i') ?? '—' }}</td>
                            <td>{{ $entry->reasonLabel() }}</td>
                            <td class="{{ $entry->delta < 0 ? 'admin-credit-spend' : 'admin-credit-grant' }}">
                                {{ $entry->delta > 0 ? '+' : '' }}{{ $entry->delta }}
                            </td>
                            <td>{{ $entry->balance_after }}</td>
                            <td class="admin-muted">
                                @if ($entry->event)
                                    {{ $entry->event->name }}
                                @elseif ($entry->payment)
                                    {{ $entry->payment->plan_key }}
                                @else
                                    {{ $entry->note ?? '—' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="admin-muted">No credit movements yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
