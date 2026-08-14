<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/templates.css') }}">
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

    <form method="get" action="{{ route('templates.index') }}" class="tpl-filters">
        <label class="tpl-search">
            <span class="tpl-sr-only">Search templates</span>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $q }}" placeholder="Search by name or description" maxlength="120">
        </label>

        <label class="tpl-category-label">
            <span class="tpl-sr-only">Category</span>
            <select name="category">
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->slug }}" @selected($categorySlug === $cat->slug)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="btn-primary">Search</button>
        @if ($q !== '' || $categorySlug)
            <a href="{{ route('templates.index') }}" class="btn-outline">Clear</a>
        @endif
    </form>

    <div class="tpl-grid">
        @forelse ($templates as $tpl)
            <article class="tpl-card tpl-gallery-card">
                <div class="tpl-gallery-thumb tpl-card-visual" style="--tpl-primary: {{ $tpl->default_theme['primary'] ?? '#1e47bb' }}; --tpl-accent: {{ $tpl->default_theme['accent'] ?? '#e00e4f' }}; --tpl-bg: {{ $tpl->default_theme['background'] ?? '#fafafa' }};">
                    @if ($tpl->preview_image_url)
                        <img src="{{ $tpl->preview_image_url }}" alt="{{ $tpl->name }} thumbnail" width="640" height="800" loading="lazy" decoding="async">
                    @else
                        <div class="tpl-card-placeholder">
                            <a href="{{ route('templates.preview', $tpl) }}" class="btn-outline tpl-placeholder-preview-btn">
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                Preview
                                <span class="tpl-sr-only">{{ $tpl->name }}</span>
                            </a>
                        </div>
                    @endif
                </div>

                <div class="tpl-gallery-body">
                    <h2 class="tpl-gallery-title">{{ $tpl->name }}</h2>

                    <div class="tpl-gallery-block">
                        <span class="tpl-gallery-label" id="tpl-cat-label-{{ $tpl->id }}">Categories</span>
                        <div class="tpl-gallery-categories" role="group" aria-labelledby="tpl-cat-label-{{ $tpl->id }}">
                            @forelse ($tpl->categories as $cat)
                                <span class="tpl-tag">{{ $cat->name }}</span>
                            @empty
                                <span class="tpl-gallery-empty">—</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="tpl-gallery-block tpl-gallery-tier-row">
                        <span class="tpl-gallery-label">Plan</span>
                        <span class="tpl-tier-badge tpl-tier-{{ str_replace('_', '-', $tpl->requiredTier()->value) }}">{{ $tpl->requiredTier()->label() }}</span>
                    </div>

                    <div class="tpl-gallery-actions">
                        @if ($tpl->preview_image_url)
                            <a href="{{ route('templates.preview', $tpl) }}" class="btn-outline tpl-gallery-preview-btn">
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                Preview
                            </a>
                        @endif
                        @if (auth()->user()?->canUseInvitationTemplate($tpl))
                            <a href="{{ route('events.create', ['template' => $tpl->slug]) }}" class="btn-primary tpl-btn-small tpl-gallery-use-btn">Use template</a>
                        @else
                            <span class="tpl-gallery-lock-hint">Requires {{ $tpl->requiredTier()->label() }} to apply.</span>
                            <div class="tpl-tier-actions tpl-gallery-tier-actions">
                                <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier($tpl->requiredTier()) }}" class="btn-outline tpl-btn-small">Upgrade to {{ $tpl->requiredTier()->label() }}</a>
                                @guest
                                    <a href="{{ route('register') }}" class="btn-primary tpl-btn-small">Get started</a>
                                @endguest
                            </div>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <p class="tpl-empty">No templates match your filters.</p>
        @endforelse
    </div>

</x-app-layout>
