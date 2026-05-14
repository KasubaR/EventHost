@php
    use App\Support\InvitationFonts;
    use App\Support\InvitationLayoutVariant;

    $sectionLabels = [
        'hero' => 'Cover & hero',
        'details' => 'Title & event details',
        'description' => 'Event description',
        'story' => 'Story',
        'schedule' => 'Schedule',
        'rsvp' => 'RSVP banner',
        'countdown' => 'Countdown',
        'gallery' => 'Photo gallery',
    ];

    $rawScheduleRows = old('schedule_items');
    $mergedSchedule = $invitationMerged['content']['schedule'] ?? [];
    if (is_array($rawScheduleRows)) {
        $scheduleRows = array_values($rawScheduleRows);
    } else {
        $scheduleRows = [];
        foreach ($mergedSchedule as $r) {
            $scheduleRows[] = [
                'time' => $r['time'] ?? '',
                'title' => $r['title'] ?? '',
                'detail' => $r['detail'] ?? '',
            ];
        }
    }
    while (count($scheduleRows) < 8) {
        $scheduleRows[] = ['time' => '', 'title' => '', 'detail' => ''];
    }
    $scheduleRows = array_slice($scheduleRows, 0, 16);

    $fontChoices = InvitationFonts::MAP;

    $layoutVariant = $invitationMerged['layout_variant'] ?? InvitationLayoutVariant::STANDARD;
    if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
        $sectionLabels['description'] = 'Contact & closing';
        $sectionLabels['gallery'] = 'Speaker grid';
    }
    $blockedSections = InvitationLayoutVariant::blockedSections($layoutVariant);
    $sectionLabels = array_diff_key($sectionLabels, array_flip($blockedSections));
    $heroPortraitSlots = InvitationLayoutVariant::maxInvitationHeroPortraitSlots($layoutVariant);
    $couplePhotoSlots = InvitationLayoutVariant::maxCouplePhotoSlots($layoutVariant);
    $currentCouple = array_values(array_filter(array_map('strval', $invitationMerged['media']['couple_photos'] ?? [])));
    $currentHeroPortrait = $invitationMerged['media']['hero_portrait'] ?? null;
    $currentHeroPortrait = is_string($currentHeroPortrait) && $currentHeroPortrait !== '' ? $currentHeroPortrait : null;
    $coupleSlotsRemaining = max(0, $couplePhotoSlots - count($currentCouple));
@endphp

<form method="post" action="{{ route('events.invitation-design.update', $event) }}" enctype="multipart/form-data" class="profile-form evt-design-form" id="inv-section-sortable-root">
    @csrf
    @method('patch')
    <input type="hidden" name="template_fingerprint" value="{{ $templateFingerprint }}">
    <input type="hidden" name="customization_token" value="{{ $customizationToken }}">

    <div class="evt-section">
        <div class="evt-section-head">
            <h2>Invitation design</h2>
            <p>Customize colors, typography, section order, and optional gallery or media. Template style is set in “Invitation template” above.</p>
        </div>
        <div class="evt-section-body profile-fields evt-design-fields">

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Colors</legend>
                <div class="evt-design-colors">
                    <div class="profile-field">
                        <label for="theme_primary" class="profile-label">Primary</label>
                        <input id="theme_primary" name="theme_primary" type="color" required
                               class="evt-color-input {{ $errors->has('theme_primary') ? 'profile-input--error' : '' }}"
                               value="{{ old('theme_primary', $invitationMerged['theme']['primary']) }}">
                        @error('theme_primary')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="theme_accent" class="profile-label">Accent</label>
                        <input id="theme_accent" name="theme_accent" type="color" required
                               class="evt-color-input {{ $errors->has('theme_accent') ? 'profile-input--error' : '' }}"
                               value="{{ old('theme_accent', $invitationMerged['theme']['accent']) }}">
                        @error('theme_accent')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="theme_background" class="profile-label">Background</label>
                        <input id="theme_background" name="theme_background" type="color" required
                               class="evt-color-input {{ $errors->has('theme_background') ? 'profile-input--error' : '' }}"
                               value="{{ old('theme_background', $invitationMerged['theme']['background']) }}">
                        @error('theme_background')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </fieldset>

            <div class="evt-grid-2 profile-fields">
                <div class="profile-field">
                    <label for="font_heading_key" class="profile-label">Heading font</label>
                    <select id="font_heading_key" name="font_heading_key" required class="profile-input {{ $errors->has('font_heading_key') ? 'profile-input--error' : '' }}">
                        @foreach (array_keys($fontChoices) as $key)
                            <option value="{{ $key }}" @selected(old('font_heading_key', $invitationMerged['theme']['font_heading_key']) === $key)>{{ ucwords(str_replace('_', ' ', $key)) }}</option>
                        @endforeach
                    </select>
                    @error('font_heading_key')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
                <div class="profile-field">
                    <label for="font_body_key" class="profile-label">Body font</label>
                    <select id="font_body_key" name="font_body_key" required class="profile-input {{ $errors->has('font_body_key') ? 'profile-input--error' : '' }}">
                        @foreach (array_keys($fontChoices) as $key)
                            <option value="{{ $key }}" @selected(old('font_body_key', $invitationMerged['theme']['font_body_key']) === $key)>{{ ucwords(str_replace('_', ' ', $key)) }}</option>
                        @endforeach
                    </select>
                    @error('font_body_key')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>

            <input type="hidden" name="animation_subtle" value="0">
            <label class="profile-label evt-check-label">
                <input type="checkbox" name="animation_subtle" value="1" class="profile-input evt-check-input"
                       @checked(old('animation_subtle', $invitationMerged['effects']['animation_subtle'] ? '1' : '0') === '1')>
                Subtle motion on the invitation page
            </label>

            @if (! in_array('countdown', $blockedSections, true) || $layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES)
                <input type="hidden" name="countdown_enabled" value="0">
                <label class="profile-label evt-check-label">
                    <input type="checkbox" name="countdown_enabled" value="1" class="profile-input evt-check-input"
                           @checked(old('countdown_enabled', ($invitationMerged['effects']['countdown_enabled'] ?? true) ? '1' : '0') === '1')>
                    Live countdown on the public invitation
                </label>
            @endif

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Sections</legend>
                <p class="evt-muted evt-design-hint">Drag rows to reorder. Toggle visibility for each block.</p>
                @error('section_order')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
                <ul class="evt-design-section-list" data-inv-sortable-list>
                    @foreach ($invitationMerged['sections'] as $section)
                        @php
                            $type = $section['type'];
                            $label = $sectionLabels[$type] ?? $type;
                            $visOld = old('section_visible.'.$type);
                            $checked = $visOld !== null ? $visOld === '1' || $visOld === true || $visOld === 1 : (bool) $section['visible'];
                        @endphp
                        <li class="evt-design-section-row" data-section-type="{{ $type }}">
                            <input type="hidden" name="section_order[]" value="{{ $type }}">
                            <button type="button" class="evt-design-drag" data-inv-sort-handle aria-label="Reorder {{ $label }}">
                                <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
                            </button>
                            <span class="evt-design-section-label">{{ $label }}</span>
                            <input type="hidden" name="section_visible[{{ $type }}]" value="0">
                            <label class="evt-design-vis-label">
                                <input type="checkbox" name="section_visible[{{ $type }}]" value="1" class="evt-check-input" @checked($checked)>
                                Visible
                            </label>
                        </li>
                    @endforeach
                </ul>
            </fieldset>

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Story &amp; schedule</legend>
                <p class="evt-muted evt-design-hint">Story is separate from the short event description above. Schedule rows with an empty title are ignored.</p>

                <div class="profile-field">
                    <label for="content_story" class="profile-label">Story</label>
                    <textarea id="content_story" name="content_story" rows="5" maxlength="12000"
                              class="profile-input {{ $errors->has('content_story') ? 'profile-input--error' : '' }}"
                              placeholder="Optional longer narrative for guests">{{ old('content_story', $invitationMerged['content']['story'] ?? '') }}</textarea>
                    @error('content_story')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="evt-design-schedule">
                    <p class="profile-label">Schedule items</p>
                    <ul class="evt-design-schedule-list">
                        @foreach ($scheduleRows as $idx => $schedRow)
                            @php
                                $sr = is_array($schedRow) ? $schedRow : [];
                                $tTime = (string) ($sr['time'] ?? '');
                                $tTitle = (string) ($sr['title'] ?? '');
                                $tDetail = (string) ($sr['detail'] ?? '');
                            @endphp
                            <li class="evt-design-schedule-row">
                                <div class="evt-design-schedule-fields">
                                    <div class="profile-field">
                                        <label class="profile-label evt-design-schedule-label" for="schedule_time_{{ $idx }}">Time</label>
                                        <input id="schedule_time_{{ $idx }}" name="schedule_items[{{ $idx }}][time]" type="text" maxlength="48"
                                               class="profile-input" value="{{ $tTime }}" placeholder="e.g. 4:00 PM">
                                    </div>
                                    <div class="profile-field">
                                        <label class="profile-label evt-design-schedule-label" for="schedule_title_{{ $idx }}">Title</label>
                                        <input id="schedule_title_{{ $idx }}" name="schedule_items[{{ $idx }}][title]" type="text" maxlength="160"
                                               class="profile-input" value="{{ $tTitle }}" placeholder="Ceremony, Reception…">
                                    </div>
                                    <div class="profile-field evt-design-schedule-detail-field">
                                        <label class="profile-label evt-design-schedule-label" for="schedule_detail_{{ $idx }}">Detail</label>
                                        <input id="schedule_detail_{{ $idx }}" name="schedule_items[{{ $idx }}][detail]" type="text" maxlength="500"
                                               class="profile-input" value="{{ $tDetail }}" placeholder="Optional location or notes">
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @error('schedule_items')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </fieldset>

            @if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES)
                @php
                    $rawSpk = old('speaker_cards');
                    $spkRows = [];
                    if (is_array($rawSpk)) {
                        foreach (array_slice($rawSpk, 0, 4) as $r) {
                            $spkRows[] = is_array($r)
                                ? ['role' => (string) ($r['role'] ?? ''), 'name' => (string) ($r['name'] ?? '')]
                                : ['role' => '', 'name' => ''];
                        }
                    } else {
                        foreach ($invitationMerged['content']['speaker_cards'] ?? [] as $r) {
                            if (is_array($r)) {
                                $spkRows[] = ['role' => (string) ($r['role'] ?? ''), 'name' => (string) ($r['name'] ?? '')];
                            }
                        }
                    }
                    while (count($spkRows) < 4) {
                        $spkRows[] = ['role' => '', 'name' => ''];
                    }
                    $spkRows = array_slice($spkRows, 0, 4);
                @endphp
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Beauty for Ashes — conference copy</legend>
                    <p class="evt-muted evt-design-hint">Optional copy for the jewel-tone layout. The first four <strong>gallery</strong> images map to speakers in order. Use “Invitation hero photos” below for up to four speaker portrait uploads shown on the grid.</p>

                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="bfa_presenter_line" class="profile-label">Presenter / ministry line</label>
                            <input id="bfa_presenter_line" name="bfa_presenter_line" type="text" maxlength="200" class="profile-input"
                                   value="{{ old('bfa_presenter_line', $invitationMerged['content']['bfa_presenter_line'] ?? '') }}"
                                   placeholder="e.g. New Breed Christian Ministries International">
                        </div>
                        <div class="profile-field">
                            <label for="bfa_presents_line" class="profile-label">“Presents” line</label>
                            <input id="bfa_presents_line" name="bfa_presents_line" type="text" maxlength="120" class="profile-input"
                                   value="{{ old('bfa_presents_line', $invitationMerged['content']['bfa_presents_line'] ?? '') }}"
                                   placeholder="Presents">
                        </div>
                    </div>
                    <div class="profile-field">
                        <label for="bfa_tagline_bar" class="profile-label">Subtitle under the main title</label>
                        <input id="bfa_tagline_bar" name="bfa_tagline_bar" type="text" maxlength="200" class="profile-input"
                               value="{{ old('bfa_tagline_bar', $invitationMerged['content']['bfa_tagline_bar'] ?? '') }}"
                               placeholder="e.g. New Breed of Women Conference">
                    </div>
                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="bfa_conference_theme" class="profile-label">Conference theme</label>
                            <input id="bfa_conference_theme" name="bfa_conference_theme" type="text" maxlength="160" class="profile-input"
                                   value="{{ old('bfa_conference_theme', $invitationMerged['content']['bfa_conference_theme'] ?? '') }}">
                        </div>
                        <div class="profile-field">
                            <label for="bfa_dress_code" class="profile-label">Dress code</label>
                            <input id="bfa_dress_code" name="bfa_dress_code" type="text" maxlength="160" class="profile-input"
                                   value="{{ old('bfa_dress_code', $invitationMerged['content']['bfa_dress_code'] ?? '') }}">
                        </div>
                    </div>
                    <div class="profile-field">
                        <label for="venue_note" class="profile-label">Venue directions (extra line)</label>
                        <input id="venue_note" name="venue_note" type="text" maxlength="500" class="profile-input"
                               value="{{ old('venue_note', $invitationMerged['content']['venue_note'] ?? '') }}"
                               placeholder="Gate, landmark, parking…">
                    </div>
                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="contact_phone_primary" class="profile-label">Contact phone (primary)</label>
                            <input id="contact_phone_primary" name="contact_phone_primary" type="text" maxlength="40" class="profile-input"
                                   value="{{ old('contact_phone_primary', $invitationMerged['content']['contact_phone_primary'] ?? '') }}">
                        </div>
                        <div class="profile-field">
                            <label for="contact_phone_secondary" class="profile-label">Contact phone (secondary)</label>
                            <input id="contact_phone_secondary" name="contact_phone_secondary" type="text" maxlength="40" class="profile-input"
                                   value="{{ old('contact_phone_secondary', $invitationMerged['content']['contact_phone_secondary'] ?? '') }}">
                        </div>
                    </div>

                    <p class="profile-label evt-bfa-speaker-heading">Speaker names (optional)</p>
                    <ul class="evt-design-schedule-list">
                        @foreach ($spkRows as $idx => $sp)
                            <li class="evt-design-schedule-row">
                                <div class="evt-design-schedule-fields">
                                    <div class="profile-field">
                                        <label class="profile-label evt-design-schedule-label" for="speaker_role_{{ $idx }}">Role</label>
                                        <input id="speaker_role_{{ $idx }}" name="speaker_cards[{{ $idx }}][role]" type="text" maxlength="80" class="profile-input"
                                               value="{{ $sp['role'] }}" placeholder="e.g. Prophetess">
                                    </div>
                                    <div class="profile-field">
                                        <label class="profile-label evt-design-schedule-label" for="speaker_name_{{ $idx }}">Name</label>
                                        <input id="speaker_name_{{ $idx }}" name="speaker_cards[{{ $idx }}][name]" type="text" maxlength="120" class="profile-input"
                                               value="{{ $sp['name'] }}" placeholder="Full name">
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @error('speaker_cards')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </fieldset>
            @endif

            @if ($heroPortraitSlots === 0 && $couplePhotoSlots === 0)
                <p class="evt-muted evt-design-hint">The invitation hero image uses your <strong>event cover photo</strong> (edit under Event details).</p>
            @endif

            @if ($heroPortraitSlots > 0 || $couplePhotoSlots > 0)
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Invitation hero photos</legend>
                    <p class="evt-muted evt-design-hint">
                        @if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES)
                            Optional speaker portraits for the grid (up to four). When empty, the first gallery images are used as headshots, or a soft placeholder appears.
                        @else
                            Optional portraits beside your headline. When empty, the botanical layout falls back to your event cover for the framed photo.
                        @endif
                    </p>

                    @if ($heroPortraitSlots > 0)
                        @if ($currentHeroPortrait !== null)
                            <div class="evt-design-hero-current profile-field">
                                <p class="profile-label">Hero portrait</p>
                                <div class="evt-design-gallery-current evt-design-hero-preview">
                                    <img src="{{ asset('storage/'.$currentHeroPortrait) }}" alt="" width="120" height="150" loading="lazy">
                                </div>
                                <input type="hidden" name="clear_hero_portrait" value="0">
                                <label class="profile-label evt-check-label">
                                    <input type="checkbox" name="clear_hero_portrait" value="1" class="evt-check-input" @checked(old('clear_hero_portrait') === '1')>
                                    Remove hero portrait (use event cover)
                                </label>
                            </div>
                        @endif
                        <div class="profile-field">
                            <label for="invitation_hero_portrait" class="profile-label">{{ $currentHeroPortrait ? 'Replace hero portrait' : 'Upload hero portrait' }}</label>
                            <input id="invitation_hero_portrait" name="invitation_hero_portrait" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="profile-input">
                            @error('invitation_hero_portrait')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                    @endif

                    @if ($couplePhotoSlots > 0)
                        @if ($currentCouple !== [])
                            <ul class="evt-design-gallery-current evt-design-couple-current">
                                @foreach ($currentCouple as $path)
                                    <li>
                                        <img src="{{ asset('storage/'.$path) }}" alt="" width="96" height="120" loading="lazy">
                                        <label class="evt-design-remove-label">
                                            <input type="checkbox" name="couple_remove[]" value="{{ $path }}"> Remove
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="profile-field">
                            <label for="couple_photos" class="profile-label">
                                @if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES)
                                    Speaker portrait uploads
                                @else
                                    Couple / dual portraits
                                @endif
                            </label>
                            <input id="couple_photos" name="couple_photos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="profile-input" multiple @if ($coupleSlotsRemaining === 0) disabled @endif>
                            <p class="evt-muted evt-design-hint">Up to {{ $couplePhotoSlots }} image(s).
                                @if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES)
                                    Shown on the speaker grid in upload order.
                                @else
                                    Displayed as one or two framed portraits in the hero.
                                @endif
                                {{ $coupleSlotsRemaining === 0 ? 'Remove one to add another.' : $coupleSlotsRemaining.' slot(s) left.' }}
                            </p>
                            @error('couple_photos')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                            @error('couple_remove')
                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                            @enderror
                        </div>
                    @endif
                </fieldset>
            @endif

            @if (! in_array('gallery', $blockedSections, true))
            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Gallery</legend>
                <p class="evt-muted evt-design-hint">Up to five WebP images stored after upload (converted from JPG/PNG).</p>
                @if (! empty($invitationMerged['media']['gallery']))
                    <ul class="evt-design-gallery-current">
                        @foreach ($invitationMerged['media']['gallery'] as $path)
                            <li>
                                <img src="{{ asset('storage/'.$path) }}" alt="" width="96" height="72" loading="lazy">
                                <label class="evt-design-remove-label">
                                    <input type="checkbox" name="gallery_remove[]" value="{{ $path }}"> Remove
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="profile-field">
                    <label for="gallery_images" class="profile-label">Add images</label>
                    <input id="gallery_images" name="gallery_images[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="profile-input" multiple>
                    @error('gallery_images')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                    @error('gallery_remove')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </fieldset>
            @endif

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Background video &amp; music</legend>
                <p class="evt-muted evt-design-hint">Short looping clips and optional audio with an explicit play button for guests.</p>

                @if (! empty($invitationMerged['effects']['video_background']))
                    <p class="evt-design-current-media">Current video: <code>{{ $invitationMerged['effects']['video_background'] }}</code></p>
                @endif
                <input type="hidden" name="clear_video" value="0">
                <label class="profile-label evt-check-label">
                    <input type="checkbox" name="clear_video" value="1" class="evt-check-input" @checked(old('clear_video') === '1')>
                    Remove background video
                </label>
                <div class="profile-field">
                    <label for="video_background" class="profile-label">Upload MP4 or WebM</label>
                    <input id="video_background" name="video_background" type="file" accept="video/mp4,video/webm" class="profile-input">
                    @error('video_background')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                @if (! empty($invitationMerged['effects']['audio_track']))
                    <p class="evt-design-current-media">Current audio: <code>{{ $invitationMerged['effects']['audio_track'] }}</code></p>
                @endif
                <input type="hidden" name="clear_audio" value="0">
                <label class="profile-label evt-check-label">
                    <input type="checkbox" name="clear_audio" value="1" class="evt-check-input" @checked(old('clear_audio') === '1')>
                    Remove music track
                </label>
                <div class="profile-field">
                    <label for="audio_track" class="profile-label">Upload MP3 or OGG</label>
                    <input id="audio_track" name="audio_track" type="file" accept="audio/mpeg,audio/mp3,audio/ogg,audio/wav" class="profile-input">
                    @error('audio_track')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </fieldset>

            <div class="evt-design-actions evt-actions-bar">
                @error('customization_token')
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                @enderror
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Save invitation design
                </button>
            </div>
        </div>
    </div>
</form>
