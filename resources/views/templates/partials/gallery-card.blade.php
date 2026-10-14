{{-- One template on the public gallery. Expects $tpl (an InvitationTemplate with categories loaded). --}}
@php
    $tier = $tpl->requiredTier();
    $previewUrl = route('templates.preview', $tpl);
    $categoryLine = $tpl->categories->take(2)->pluck('name')->join(' · ');

    // Theme colours land in a style attribute, so only plain hex values get through.
    $hex = fn ($value, string $fallback): string => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    $theme = is_array($tpl->default_theme) ? $tpl->default_theme : [];

    if (! auth()->check()) {
        [$actionUrl, $actionLabel] = [route('register'), 'Use template'];
    } elseif (auth()->user()->canUseInvitationTemplate($tpl)) {
        [$actionUrl, $actionLabel] = [route('events.create', ['template' => $tpl->slug]), 'Use template'];
    } else {
        [$actionUrl, $actionLabel] = [\App\Support\BillingPlan::checkoutUrlForTier($tier), 'Upgrade to '.$tier->label()];
    }
@endphp

<article class="tplg-card">
    <a href="{{ $previewUrl }}" class="tplg-card-media" aria-label="Preview {{ $tpl->name }}">
        @if ($tpl->preview_image_url)
            <img src="{{ $tpl->preview_image_url }}" alt="" width="600" height="800" loading="lazy" decoding="async">
        @else
            <span class="tplg-card-placeholder" style="--tplg-primary: {{ $hex($theme['primary'] ?? null, '#1e47bb') }}; --tplg-accent: {{ $hex($theme['accent'] ?? null, '#e00e4f') }}; --tplg-bg: {{ $hex($theme['background'] ?? null, '#fafafa') }};" aria-hidden="true">
                <span class="tplg-card-placeholder-name">{{ $tpl->name }}</span>
            </span>
        @endif
        <span class="tplg-badge tplg-badge--{{ str_replace('_', '-', $tier->value) }}">{{ $tier === \App\Enums\SubscriptionTier::None ? 'Free' : $tier->label() }}</span>
        <span class="tplg-card-peek" aria-hidden="true"><i class="fa-regular fa-eye"></i> Preview</span>
    </a>

    <div class="tplg-card-body">
        <h3 class="tplg-card-title">{{ $tpl->name }}</h3>
        @if ($categoryLine !== '')
            <p class="tplg-card-cats">{{ $categoryLine }}</p>
        @endif

        <div class="tplg-card-actions">
            <a href="{{ $previewUrl }}" class="tplg-btn tplg-btn--ghost">Preview</a>
            <a href="{{ $actionUrl }}" class="tplg-btn tplg-btn--solid">{{ $actionLabel }}</a>
        </div>
    </div>
</article>
