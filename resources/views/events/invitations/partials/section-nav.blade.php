{{-- Built by App\Support\InvitationSectionNav::items(). The bar is a bare <nav>, so
     global.css's nav {} rule applies — events-invitation-section-nav.css overrides it
     via nav.evt-inv-nav, the same pattern .dash-nav and nav.set-tabs use. --}}
<nav class="evt-inv-nav" aria-label="Invitation sections">
    <ul class="evt-inv-nav-list">
        @foreach ($items as $item)
            <li>
                <a href="#{{ $item['id'] }}" class="evt-inv-nav-link">{{ $item['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
