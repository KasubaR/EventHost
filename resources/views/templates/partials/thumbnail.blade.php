{{--
    Shared template thumbnail: image (or gradient placeholder), tier badge and
    "Preview" affordance. Included by both templates/index.blade.php (the
    library) and events/choose-template.blade.php (the wizard step) so the
    two can't visually drift apart again.
--}}
@props(['tpl', 'previewUrl'])

<a href="{{ $previewUrl }}" class="tpl-card-visual" style="--tpl-primary: {{ $tpl->default_theme['primary'] ?? '#1e47bb' }}; --tpl-accent: {{ $tpl->default_theme['accent'] ?? '#e00e4f' }}; --tpl-bg: {{ $tpl->default_theme['background'] ?? '#fafafa' }};">
    @if ($tpl->preview_image_url)
        <img src="{{ $tpl->preview_image_url }}" alt="{{ $tpl->name }} thumbnail" width="640" height="800" loading="lazy" decoding="async">
    @else
        <div class="tpl-card-placeholder" aria-hidden="true"></div>
    @endif
    <span class="tpl-tier-badge tpl-thumb-tier tpl-tier-{{ str_replace('_', '-', $tpl->requiredTier()->value) }}">
        <span class="tpl-sr-only">Plan:</span> {{ $tpl->requiredTier()->label() }}
    </span>
    <span class="tpl-card-preview-chip"><i class="fa-regular fa-eye" aria-hidden="true"></i> Preview</span>
</a>
