@php
    /** @var list<string> $couple */
    $couple = array_values(array_filter(array_map('strval', $invitation['media']['couple_photos'] ?? [])));
    /** @var list<string> $gallery */
    $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
    /** @var list<array{role: string, name: string}> $cards */
    $cards = $invitation['content']['speaker_cards'] ?? [];
    if (! is_array($cards)) {
        $cards = [];
    }

    $hostSlot = max(0, min(3, (int) ($invitation['content']['bfa_host_slot'] ?? 1)));
    $slots = [];
    for ($i = 0; $i < 4; $i++) {
        $row = is_array($cards[$i] ?? null) ? $cards[$i] : [];
        $role = trim((string) ($row['role'] ?? ''));
        $nm = trim((string) ($row['name'] ?? ''));
        $hasContent = $role !== '' || $nm !== '';
        if (! $hasContent) {
            $role = 'Speaker';
            $nm = 'To be announced';
        }
        $src = null;
        if (isset($couple[$i]) && $couple[$i] !== '') {
            $src = asset('storage/'.$couple[$i]);
        } elseif (isset($gallery[$i]) && $gallery[$i] !== '') {
            $src = asset('storage/'.$gallery[$i]);
        }
        $slots[] = ['role' => $role, 'name' => $nm, 'src' => $src, 'featured' => $i === $hostSlot && $hasContent];
    }
@endphp

<section class="bfa-section bfa-speakers" id="speakers">
    <div class="bfa-section-inner">
        <div class="bfa-section-head">
            <div class="bfa-section-label">Featured speakers</div>
            <h2 class="bfa-section-heading">Meet the ministers</h2>
            <p class="bfa-section-lead">Anointed voices gathered to speak life, restoration, and purpose.</p>
        </div>

        <div class="bfa-speaker-grid">
            @foreach ($slots as $slot)
                <article class="bfa-speaker-card @if ($slot['featured']) bfa-speaker-card--featured @endif">
                    <div class="bfa-speaker-photo">
                        @if ($slot['featured'])
                            <div class="bfa-host-badge">Host</div>
                        @endif
                        @if ($slot['src'])
                            <img src="{{ $slot['src'] }}" alt="" width="400" height="533" loading="lazy">
                        @else
                            <img src="{{ asset('images/person-placeholder.jpg') }}" alt="" width="400" height="533" loading="lazy">
                        @endif
                    </div>
                    <div class="bfa-speaker-body">
                        <div class="bfa-speaker-role">{{ $slot['role'] }}</div>
                        <div class="bfa-speaker-name">{{ $slot['name'] }}</div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
