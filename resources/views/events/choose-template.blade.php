<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/templates.css') }}">
    @endpush

    <x-slot name="title">Choose invitation layout — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Choose an invitation layout</h1>
                <p class="dph-sub">{{ $event->name }} — preview styles, then pick one to customize colors and sections.</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.edit', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to edit event</a>
            </div>
        </div>
    </x-slot>

    @include('events.partials.steps', ['current' => 2])

    @if (session('status') === 'draft-saved')
        <div class="profile-success evt-flash"><i class="fa-solid fa-circle-check"></i> Draft saved — choose a layout below.</div>
    @endif

    @if ($errors->has('invitation_template_id'))
        <div class="profile-field-error evt-flash">
            <i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first('invitation_template_id') }}
        </div>
    @endif

    <form method="get" action="{{ route('events.choose-template', $event) }}" class="tpl-filters">
        @if ($preferredIdInt !== null)
            <input type="hidden" name="preferred" value="{{ $preferredIdInt }}">
        @endif
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
            <a href="{{ route('events.choose-template', ['event' => $event] + ($preferredIdInt !== null ? ['preferred' => $preferredIdInt] : [])) }}" class="btn-outline">Clear</a>
        @endif
    </form>

    <div class="tpl-grid evt-choose-template-grid">
        @forelse ($templates as $tpl)
            <article class="tpl-card @if ($preferredIdInt !== null && (int) $tpl->id === $preferredIdInt) tpl-card--suggested @endif" id="tpl-suggestion-{{ $tpl->id }}">
                <a href="{{ route('templates.preview', $tpl) }}" class="tpl-card-visual" style="--tpl-primary: {{ $tpl->default_theme['primary'] ?? '#6c5ce7' }}; --tpl-accent: {{ $tpl->default_theme['accent'] ?? '#0ea5e9' }}; --tpl-bg: {{ $tpl->default_theme['background'] ?? '#fafafa' }};">
                    @if ($tpl->preview_image_url)
                        <img src="{{ $tpl->preview_image_url }}" alt="" width="640" height="800" loading="lazy" decoding="async">
                    @else
                        <div class="tpl-card-placeholder" aria-hidden="true"></div>
                    @endif
                    <span class="tpl-card-preview-chip"><i class="fa-regular fa-eye"></i> Preview</span>
                </a>
                <div class="tpl-card-body">
                    <h2 class="tpl-card-title-row">
                        {{ $tpl->name }}
                        <span class="tpl-tier-badge tpl-tier-{{ str_replace('_', '-', $tpl->requiredTier()->value) }}">{{ $tpl->requiredTier()->label() }}</span>
                    </h2>
                    @if ($preferredIdInt !== null && (int) $tpl->id === $preferredIdInt)
                        <p class="evt-tpl-suggested-note"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i> Suggested from the template library</p>
                    @endif
                    @if ($tpl->description)
                        <p>{{ $tpl->description }}</p>
                    @endif
                    <div class="tpl-card-tags">
                        @foreach ($tpl->categories as $cat)
                            <span class="tpl-tag">{{ $cat->name }}</span>
                        @endforeach
                    </div>
                    @if (auth()->user()?->canUseInvitationTemplate($tpl))
                        <form method="post" action="{{ route('events.choose-template.update', $event) }}" class="evt-tpl-select-form">
                            @csrf
                            @method('patch')
                            <input type="hidden" name="invitation_template_id" value="{{ $tpl->id }}">
                            <button type="submit" class="btn-primary tpl-btn-small">
                                <i class="fa-solid fa-check" aria-hidden="true"></i> Use this layout
                            </button>
                        </form>
                    @else
                        @if (auth()->user()?->isActive())
                            <p class="tpl-tier-lock-msg">Requires {{ $tpl->requiredTier()->label() }}. Preview anytime — upgrade to apply.</p>
                        @else
                            <p class="tpl-tier-lock-msg">Requires an active subscription. Preview anytime — subscribe to apply.</p>
                        @endif
                        <div class="tpl-tier-actions">
                            <a href="{{ url('/') }}#pricing" class="btn-outline tpl-btn-small">View plans</a>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <p class="tpl-empty">No templates match your filters.</p>
        @endforelse
    </div>

</x-app-layout>
