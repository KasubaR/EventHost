<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/templates.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/templates-library.js') }}" defer></script>
    @endpush

    <x-slot name="title">Templates</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Templates</h1>
                <p class="dph-sub">Browse thumbnails by category, check Base or Pro, and open a full preview before you create an event.</p>
            </div>
        </div>
    </x-slot>

    @php
        $planTabs = ['' => 'All templates', 'base' => 'Base', 'pro' => 'Pro'];
        $keepFilters = array_filter(['q' => $q, 'category' => $categorySlug], fn ($v) => filled($v));
    @endphp

    <nav class="tpl-plan-tabs" aria-label="Filter by plan">
        @foreach ($planTabs as $tabKey => $tabLabel)
            @php $isActive = ($plan ?? '') === $tabKey; @endphp
            <a href="{{ route('templates.index', array_filter($keepFilters + ['plan' => $tabKey], fn ($v) => filled($v))) }}"
               class="tpl-plan-tab {{ $isActive ? 'is-active' : '' }}"
               @if ($isActive) aria-current="page" @endif>
                {{ $tabLabel }}
            </a>
        @endforeach
    </nav>

    <form method="get" action="{{ route('templates.index') }}" class="tpl-filters">
        @if ($plan)
            <input type="hidden" name="plan" value="{{ $plan }}">
        @endif
        <label class="tpl-search">
            <span class="tpl-sr-only">Search templates</span>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $q }}" placeholder="Search by name or description" maxlength="120">
        </label>

        <div class="tpl-category-label">
            <label for="tpl-category" class="tpl-sr-only">Category</label>
            <select id="tpl-category" name="category" data-cs data-cs-search="never" data-cs-icon="fa-solid fa-layer-group" data-tpl-autosubmit>
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->slug }}" @selected($categorySlug === $cat->slug)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn-primary">Search</button>
        @if ($q !== '' || $categorySlug)
            <a href="{{ route('templates.index', array_filter(['plan' => $plan])) }}" class="btn-outline">Clear</a>
        @endif
    </form>

    <div class="tpl-grid">
        @forelse ($templates as $tpl)
            <article class="tpl-card tpl-gallery-card">
                <div class="tpl-gallery-thumb tpl-card-visual" style="--tpl-primary: {{ $tpl->default_theme['primary'] ?? '#1e47bb' }}; --tpl-accent: {{ $tpl->default_theme['accent'] ?? '#e00e4f' }}; --tpl-bg: {{ $tpl->default_theme['background'] ?? '#fafafa' }};">
                    @if ($tpl->preview_image_url)
                        <img src="{{ $tpl->preview_image_url }}" alt="{{ $tpl->name }} thumbnail" width="640" height="800" loading="lazy" decoding="async">
                    @else
                        <div class="tpl-card-placeholder"></div>
                    @endif
                    <span class="tpl-tier-badge tpl-thumb-tier tpl-tier-{{ str_replace('_', '-', $tpl->requiredTier()->value) }}">
                        <span class="tpl-sr-only">Plan:</span> {{ $tpl->requiredTier()->label() }}
                    </span>
                    <a href="{{ route('templates.preview', $tpl) }}" class="btn-outline tpl-placeholder-preview-btn">
                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        Preview
                        <span class="tpl-sr-only">{{ $tpl->name }}</span>
                    </a>
                </div>

                <div class="tpl-gallery-body">
                    <h2 class="tpl-gallery-title">{{ $tpl->name }}</h2>

                    @unless (auth()->user()?->canUseInvitationTemplate($tpl))
                        <div class="tpl-gallery-actions">
                            <span class="tpl-gallery-lock-hint">Requires {{ $tpl->requiredTier()->label() }} to apply.</span>
                            <div class="tpl-tier-actions tpl-gallery-tier-actions">
                                <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier($tpl->requiredTier()) }}" class="btn-outline tpl-btn-small">Upgrade to {{ $tpl->requiredTier()->label() }}</a>
                                @guest
                                    <a href="{{ route('register') }}" class="btn-primary tpl-btn-small">Get started</a>
                                @endguest
                            </div>
                        </div>
                    @endunless
                </div>
            </article>
        @empty
            <p class="tpl-empty">No templates match your filters.</p>
        @endforelse
    </div>

</x-app-layout>
