@php
    // Icon + accessible label per configured profile. Anything without a URL in
    // config/social.php is skipped, so the block disappears entirely until the
    // accounts exist rather than rendering dead "#" links.
    $profiles = collect([
        'x' => ['label' => 'X', 'icon' => 'fa-brands fa-x-twitter'],
        'linkedin' => ['label' => 'LinkedIn', 'icon' => 'fa-brands fa-linkedin-in'],
        'facebook' => ['label' => 'Facebook', 'icon' => 'fa-brands fa-facebook-f'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram'],
    ])->map(fn (array $meta, string $key): array => $meta + ['url' => config("social.{$key}")])
      ->filter(fn (array $meta): bool => filled($meta['url']));
@endphp

@if ($profiles->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'social-links']) }}>
        @foreach ($profiles as $profile)
            <a href="{{ $profile['url'] }}"
               class="social-link"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="{{ $profile['label'] }}">
                <i class="{{ $profile['icon'] }}" aria-hidden="true"></i>
            </a>
        @endforeach
    </div>
@endif
