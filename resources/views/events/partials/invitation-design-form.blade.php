@php
    use App\Support\InvitationFonts;
    use App\Support\InvitationLayoutVariant;
    use App\Support\InvitationVideoBackground;

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
    if (count($scheduleRows) === 0) {
        $scheduleRows[] = ['time' => '', 'title' => '', 'detail' => ''];
    }
    $scheduleRows = array_slice($scheduleRows, 0, 16);

    $fontChoices = InvitationFonts::MAP;

    $layoutVariant = $invitationMerged['layout_variant'] ?? InvitationLayoutVariant::STANDARD;
    if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
        $sectionLabels['description'] = 'Contact & closing';
        $sectionLabels['gallery'] = 'Speaker grid';
    }
    if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION) {
        $sectionLabels['description'] = 'Save the date & couple photos';
        $sectionLabels['details'] = 'Celebration details cards';
        $sectionLabels['schedule'] = 'Detail cards (schedule data)';
    }
    if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION_NOIR) {
        $sectionLabels['description'] = 'Formal invitation card';
        $sectionLabels['story'] = 'Quote interlude';
        $sectionLabels['schedule'] = "Day's programme timeline";
    }
    $blockedSections = InvitationLayoutVariant::blockedSections($layoutVariant);
    $sectionLabels = array_diff_key($sectionLabels, array_flip($blockedSections));
    $heroPortraitSlots = InvitationLayoutVariant::maxInvitationHeroPortraitSlots($layoutVariant);
    $couplePhotoSlots = InvitationLayoutVariant::maxCouplePhotoSlots($layoutVariant);
    $currentCouple = array_values(array_filter(array_map('strval', $invitationMerged['media']['couple_photos'] ?? [])));
    $currentHeroPortrait = $invitationMerged['media']['hero_portrait'] ?? null;
    $currentHeroPortrait = is_string($currentHeroPortrait) && $currentHeroPortrait !== '' ? $currentHeroPortrait : null;
    $coupleSlotsRemaining = max(0, $couplePhotoSlots - count($currentCouple));

    $videoBackgroundStored = $invitationMerged['effects']['video_background'] ?? null;
    $videoBackgroundStored = is_string($videoBackgroundStored) && $videoBackgroundStored !== '' ? $videoBackgroundStored : null;
    $videoYoutubeFieldValue = old('video_background_youtube', InvitationVideoBackground::watchUrlFromStored($videoBackgroundStored) ?? '');
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

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Typography &amp; motion</legend>
                <p class="evt-muted evt-design-hint">Heading and body fonts, plus optional animation and countdown on the public invitation.</p>
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

                <div class="evt-check-stack">
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
                </div>
            </fieldset>

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
                <legend class="profile-label">Response form</legend>
                <p class="evt-muted evt-design-hint">Pick which RSVP questions appear for guests (the RSVP banner must stay visible under <strong>Sections</strong>), and tailor how each prompt is worded.</p>
                @php
                    use App\Services\InvitationCustomizationService;
                    $rsvpFormStored = $invitationMerged['rsvp_form'] ?? [];
                    $rsvpFormDefaultLabels = [
                        'message'             => 'Message to host',
                        'meal_preference'     => 'Meal preference',
                        'transportation_note' => 'Transportation notes',
                        'song_request'        => 'Song request',
                    ];
                @endphp
                <ul class="evt-design-rsvp-list">
                    @foreach (InvitationCustomizationService::RSVP_FORM_FIELDS as $rsvpField)
                        @php
                            $rsvpStored   = is_array($rsvpFormStored[$rsvpField] ?? null) ? $rsvpFormStored[$rsvpField] : [];
                            $rsvpDefLabel = $rsvpFormDefaultLabels[$rsvpField];
                            $rsvpLabel    = old("rsvp_form.$rsvpField.label", $rsvpStored['label'] ?? $rsvpDefLabel);
                            $rsvpVisible  = old("rsvp_form.$rsvpField.visible") !== null
                                ? old("rsvp_form.$rsvpField.visible") === '1'
                                : (bool) ($rsvpStored['visible'] ?? true);
                        @endphp
                        <li class="evt-design-rsvp-row">
                            <div class="evt-design-rsvp-row-top">
                                <span class="evt-design-rsvp-builtin">{{ $rsvpDefLabel }}</span>
                                <div class="evt-design-rsvp-toggle-wrap">
                                    <input type="hidden" name="rsvp_form[{{ $rsvpField }}][visible]" value="0">
                                    <label class="evt-design-rsvp-toggle" for="rsvp_form_visible_{{ $rsvpField }}">
                                        <input id="rsvp_form_visible_{{ $rsvpField }}"
                                               type="checkbox"
                                               name="rsvp_form[{{ $rsvpField }}][visible]"
                                               value="1"
                                               class="profile-input evt-check-input"
                                               @checked($rsvpVisible)>
                                        <span>Show on RSVP form</span>
                                    </label>
                                </div>
                            </div>
                            <div class="evt-design-rsvp-row-fields">
                                <label for="rsvp_form_label_{{ $rsvpField }}" class="evt-design-rsvp-prompt-label">Guest-facing label</label>
                                <input id="rsvp_form_label_{{ $rsvpField }}"
                                       type="text"
                                       name="rsvp_form[{{ $rsvpField }}][label]"
                                       class="profile-input evt-design-rsvp-input {{ $errors->has("rsvp_form.$rsvpField.label") ? 'profile-input--error' : '' }}"
                                       maxlength="100"
                                       value="{{ $rsvpLabel }}"
                                       placeholder="{{ $rsvpDefLabel }}">
                                @error("rsvp_form.$rsvpField.label")
                                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                @enderror
                            </div>
                        </li>
                    @endforeach
                </ul>
            </fieldset>

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Story</legend>
                <p class="evt-muted evt-design-hint">Separate from the short event description in Event details — optional longer narrative for guests.</p>
                <div class="profile-field">
                    <label for="content_story" class="profile-label">Story body</label>
                    <textarea id="content_story" name="content_story" rows="5" maxlength="12000"
                              class="profile-input {{ $errors->has('content_story') ? 'profile-input--error' : '' }}"
                              placeholder="Optional longer narrative for guests">{{ old('content_story', $invitationMerged['content']['story'] ?? '') }}</textarea>
                    @error('content_story')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </fieldset>

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Schedule</legend>
                <p class="evt-muted evt-design-hint">Rows with an empty title are ignored.</p>

                <div class="evt-design-schedule">
                    <ul class="evt-design-schedule-list" id="evt-schedule-list">
                        @foreach ($scheduleRows as $idx => $schedRow)
                            @php
                                $sr = is_array($schedRow) ? $schedRow : [];
                                $tTime = (string) ($sr['time'] ?? '');
                                $tTitle = (string) ($sr['title'] ?? '');
                                $tDetail = (string) ($sr['detail'] ?? '');
                            @endphp
                            <li class="evt-design-schedule-row">
                                <div class="evt-schedule-row-top">
                                    <button type="button" class="evt-schedule-remove-btn" data-schedule-remove aria-label="Remove row">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
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
                    <button type="button" id="evt-schedule-add-btn" class="evt-schedule-add-btn">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add row
                    </button>
                </div>

                <template id="evt-schedule-row-tpl">
                    <div class="evt-schedule-row-top">
                        <button type="button" class="evt-schedule-remove-btn" data-schedule-remove aria-label="Remove row">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="evt-design-schedule-fields">
                        <div class="profile-field">
                            <label class="profile-label evt-design-schedule-label" for="schedule_time___IDX__">Time</label>
                            <input id="schedule_time___IDX__" name="schedule_items[__IDX__][time]" type="text" maxlength="48"
                                   class="profile-input" value="" placeholder="e.g. 4:00 PM">
                        </div>
                        <div class="profile-field">
                            <label class="profile-label evt-design-schedule-label" for="schedule_title___IDX__">Title</label>
                            <input id="schedule_title___IDX__" name="schedule_items[__IDX__][title]" type="text" maxlength="160"
                                   class="profile-input" value="" placeholder="Ceremony, Reception…">
                        </div>
                        <div class="profile-field evt-design-schedule-detail-field">
                            <label class="profile-label evt-design-schedule-label" for="schedule_detail___IDX__">Detail</label>
                            <input id="schedule_detail___IDX__" name="schedule_items[__IDX__][detail]" type="text" maxlength="500"
                                   class="profile-input" value="" placeholder="Optional location or notes">
                        </div>
                    </div>
                </template>
            </fieldset>

            @if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION_NOIR)
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Noir wedding — copy &amp; footer</legend>
                    <p class="evt-muted evt-design-hint">Headlines for the split hero, formal card, photo quote, and closing monogram.</p>

                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="wi2_hero_tag" class="profile-label">Hero tagline</label>
                            <input id="wi2_hero_tag" name="wi2_hero_tag" type="text" maxlength="120" class="profile-input"
                                   value="{{ old('wi2_hero_tag', $invitationMerged['content']['wi2_hero_tag'] ?? '') }}"
                                   placeholder="The Wedding of">
                        </div>
                        <div class="profile-field">
                            <label for="wi2_invite_formal" class="profile-label">Formal card opener</label>
                            <input id="wi2_invite_formal" name="wi2_invite_formal" type="text" maxlength="160" class="profile-input"
                                   value="{{ old('wi2_invite_formal', $invitationMerged['content']['wi2_invite_formal'] ?? '') }}">
                        </div>
                    </div>
                    <div class="profile-field">
                        <label for="wi2_invite_body" class="profile-label">Formal card body</label>
                        <textarea id="wi2_invite_body" name="wi2_invite_body" rows="3" maxlength="600" class="profile-input"
                                  placeholder="request the honour of your presence…">{{ old('wi2_invite_body', $invitationMerged['content']['wi2_invite_body'] ?? '') }}</textarea>
                    </div>
                    <div class="profile-field">
                        <label for="wi2_photo_quote" class="profile-label">Photo quote</label>
                        <textarea id="wi2_photo_quote" name="wi2_photo_quote" rows="2" maxlength="400" class="profile-input">{{ old('wi2_photo_quote', $invitationMerged['content']['wi2_photo_quote'] ?? '') }}</textarea>
                    </div>
                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="wi2_photo_quote_cite" class="profile-label">Quote attribution</label>
                            <input id="wi2_photo_quote_cite" name="wi2_photo_quote_cite" type="text" maxlength="160" class="profile-input"
                                   value="{{ old('wi2_photo_quote_cite', $invitationMerged['content']['wi2_photo_quote_cite'] ?? '') }}">
                        </div>
                        <div class="profile-field">
                            <label for="wi2_footer_monogram" class="profile-label">Footer monogram</label>
                            <input id="wi2_footer_monogram" name="wi2_footer_monogram" type="text" maxlength="24" class="profile-input"
                                   value="{{ old('wi2_footer_monogram', $invitationMerged['content']['wi2_footer_monogram'] ?? '') }}"
                                   placeholder="N&amp;E">
                        </div>
                    </div>
                    <div class="profile-field">
                        <label for="wi2_footer_legal" class="profile-label">Footer closing line</label>
                        <input id="wi2_footer_legal" name="wi2_footer_legal" type="text" maxlength="120" class="profile-input"
                               value="{{ old('wi2_footer_legal', $invitationMerged['content']['wi2_footer_legal'] ?? '') }}">
                    </div>
                </fieldset>
            @endif

            @if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION)
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Wedding invitation — headlines</legend>
                    <p class="evt-muted evt-design-hint">Copy shown on the full-screen hero, couple grid caption, and closing footer.</p>

                    <div class="profile-field">
                        <label for="wi_hero_eyebrow" class="profile-label">Hero eyebrow line</label>
                        <input id="wi_hero_eyebrow" name="wi_hero_eyebrow" type="text" maxlength="120" class="profile-input"
                               value="{{ old('wi_hero_eyebrow', $invitationMerged['content']['wi_hero_eyebrow'] ?? '') }}"
                               placeholder="Together with their families">
                    </div>
                    <div class="profile-field">
                        <label for="wi_couple_caption" class="profile-label">Couple photo caption</label>
                        <input id="wi_couple_caption" name="wi_couple_caption" type="text" maxlength="160" class="profile-input"
                               value="{{ old('wi_couple_caption', $invitationMerged['content']['wi_couple_caption'] ?? '') }}"
                               placeholder="Two hearts, one story">
                    </div>
                    <div class="profile-field">
                        <label for="wi_footer_quote" class="profile-label">Footer quote</label>
                        <input id="wi_footer_quote" name="wi_footer_quote" type="text" maxlength="300" class="profile-input"
                               value="{{ old('wi_footer_quote', $invitationMerged['content']['wi_footer_quote'] ?? '') }}"
                               placeholder="A short closing line for guests">
                    </div>
                </fieldset>
            @endif

            @if ($layoutVariant === InvitationLayoutVariant::EVENT_INVITE)
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Celebration card — extra details</legend>
                    <p class="evt-muted evt-design-hint">Optional rows shown on the blush invitation card beneath the date.</p>

                    <div class="profile-field">
                        <label for="ei_color_theme" class="profile-label">Color theme</label>
                        <input id="ei_color_theme" name="ei_color_theme" type="text" maxlength="160" class="profile-input"
                               value="{{ old('ei_color_theme', $invitationMerged['content']['ei_color_theme'] ?? '') }}"
                               placeholder="e.g. Denim and Brown">
                    </div>
                    <div class="evt-grid-2 profile-fields">
                        <div class="profile-field">
                            <label for="ei_guest_speaker" class="profile-label">Guest speaker</label>
                            <input id="ei_guest_speaker" name="ei_guest_speaker" type="text" maxlength="120" class="profile-input"
                                   value="{{ old('ei_guest_speaker', $invitationMerged['content']['ei_guest_speaker'] ?? '') }}">
                        </div>
                        <div class="profile-field">
                            <label for="ei_mc" class="profile-label">MC</label>
                            <input id="ei_mc" name="ei_mc" type="text" maxlength="120" class="profile-input"
                                   value="{{ old('ei_mc', $invitationMerged['content']['ei_mc'] ?? '') }}">
                        </div>
                    </div>
                </fieldset>
            @endif

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

                    // Per-slot speaker photos (positional 4-slot array in BFA couple_photos)
                    $spkPhotos = [];
                    $rawCoupleSlots = $invitationMerged['media']['couple_photos'] ?? [];
                    for ($__i = 0; $__i < 4; $__i++) {
                        $__p = $rawCoupleSlots[$__i] ?? '';
                        $spkPhotos[$__i] = (is_string($__p) && $__p !== '') ? $__p : null;
                    }
                @endphp
                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Beauty for Ashes — presenter &amp; headline</legend>
                    <p class="evt-muted evt-design-hint">How the jewel-tone invitation introduces your ministry and the main title row.</p>

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
                </fieldset>

                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Beauty for Ashes — logistics &amp; contact</legend>
                    <p class="evt-muted evt-design-hint">Theme, venue note, and phone lines shown alongside your event details.</p>

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
                </fieldset>

                <fieldset class="evt-design-fieldset">
                    <legend class="profile-label">Beauty for Ashes — speakers</legend>
                    <div class="profile-field" style="max-width:220px; margin-bottom:14px;">
                        <label for="bfa_host_slot" class="profile-label">Host badge position</label>
                        <select id="bfa_host_slot" name="bfa_host_slot" class="profile-input">
                            @foreach (range(0, 3) as $slotIdx)
                                <option value="{{ $slotIdx }}" @selected((int) old('bfa_host_slot', $invitationMerged['content']['bfa_host_slot'] ?? 1) === $slotIdx)>
                                    Speaker {{ $slotIdx + 1 }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <p class="evt-muted evt-design-hint evt-bfa-speakers-intro">
                        <span class="evt-bfa-speakers-badge">Optional</span>
                        <span>Upload a portrait for each slot — it appears on their card on the public invitation.</span>
                    </p>
                    <div class="evt-bfa-speakers" aria-label="Speaker portrait slots">
                        <ul class="evt-design-schedule-list evt-bfa-speaker-list">
                            @foreach ($spkRows as $idx => $sp)
                                @php
                                    $spkCurrentPhoto = $spkPhotos[$idx] ?? null;
                                    $spkOrdinal = $idx + 1;
                                @endphp
                                <li class="evt-design-schedule-row evt-bfa-speaker-row" aria-label="Speaker {{ $spkOrdinal }}">
                                    <div class="evt-bfa-speaker-row-head">
                                        <span class="evt-bfa-speaker-num" aria-hidden="true">{{ $spkOrdinal }}</span>
                                        <span class="evt-bfa-speaker-row-heading">Speaker {{ $spkOrdinal }}</span>
                                    </div>
                                    <div class="evt-bfa-speaker-main">
                                        <div class="evt-bfa-speaker-photo-col">
                                            @if ($spkCurrentPhoto)
                                                <div class="evt-bfa-speaker-current-photo">
                                                    <img src="{{ asset('storage/'.$spkCurrentPhoto) }}" alt="" width="96" height="120" loading="lazy">
                                                </div>
                                                <label class="evt-design-remove-label evt-bfa-speaker-remove">
                                                    <input type="checkbox" name="speaker_photo_clear[{{ $idx }}]" value="1"
                                                           @checked(old("speaker_photo_clear.$idx") === '1')> Remove photo
                                                </label>
                                            @else
                                                <div class="evt-bfa-speaker-current-photo">
                                                    <img src="{{ asset('images/person-placeholder.jpg') }}" alt="" width="96" height="120" loading="lazy">
                                                </div>
                                            @endif
                                        </div>
                                        <div class="evt-design-schedule-fields evt-bfa-speaker-fields">
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
                                        <div class="profile-field evt-bfa-speaker-upload evt-bfa-speaker-upload-row">
                                            <label class="profile-label evt-bfa-speaker-upload-label" for="speaker_photo_{{ $idx }}">
                                                {{ $spkCurrentPhoto ? 'Replace portrait' : 'Upload portrait' }}
                                            </label>
                                            <input id="speaker_photo_{{ $idx }}"
                                                   name="speaker_photo[{{ $idx }}]"
                                                   type="file"
                                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                                   class="profile-input evt-bfa-speaker-file">
                                            @error("speaker_photo.$idx")
                                                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @error('speaker_cards')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </fieldset>
            @endif

            @if ($heroPortraitSlots === 0 && $couplePhotoSlots === 0)
                <p class="evt-muted evt-design-hint">The invitation hero image uses your <strong>event cover photo</strong> (edit under Event details).</p>
            @endif

            @if (($heroPortraitSlots > 0 || $couplePhotoSlots > 0) && $layoutVariant !== InvitationLayoutVariant::BEAUTY_FOR_ASHES)
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
                <div class="evt-design-inset-stack">
                    @if (! empty($invitationMerged['media']['gallery']))
                        <div class="evt-design-inset-panel">
                            <div class="evt-design-inset-head-row">
                                <span class="evt-design-inset-title">Uploaded images</span>
                            </div>
                            <div class="evt-design-inset-body">
                                <ul class="evt-design-gallery-current evt-design-gallery-current--in-panel">
                                    @foreach ($invitationMerged['media']['gallery'] as $path)
                                        <li>
                                            <img src="{{ asset('storage/'.$path) }}" alt="" width="96" height="72" loading="lazy">
                                            <label class="evt-design-remove-label">
                                                <input type="checkbox" name="gallery_remove[]" value="{{ $path }}"> Remove
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    <div class="evt-design-inset-panel">
                        <div class="evt-design-inset-head-row">
                            <span class="evt-design-inset-title">{{ ! empty($invitationMerged['media']['gallery']) ? 'Add more images' : 'Upload images' }}</span>
                        </div>
                        <div class="evt-design-inset-body">
                            <div class="profile-field evt-design-inset-field">
                                <label for="gallery_images" class="profile-label evt-design-upload-micro">Image files</label>
                                <input id="gallery_images" name="gallery_images[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="profile-input evt-design-media-file" multiple>
                                @error('gallery_images')
                                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                @enderror
                                @error('gallery_remove')
                                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
            @endif

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Background video</legend>
                <p class="evt-muted evt-design-hint">Optional looping video behind the hero. Paste a public YouTube link or video ID — it plays muted, similar to an uploaded clip. Use <strong>Remove</strong> to clear.</p>
                <div class="evt-design-inset-stack">
                    <div class="evt-design-inset-panel">
                        <div class="evt-design-inset-head-row">
                            <span class="evt-design-inset-title">YouTube</span>
                        </div>
                        <div class="evt-design-inset-body">
                            @if ($videoBackgroundStored !== null)
                                @if (InvitationVideoBackground::isYoutube($videoBackgroundStored))
                                    <p class="evt-design-current-media evt-design-current-media--inset">Current: YouTube background</p>
                                @else
                                    <p class="evt-design-current-media evt-design-current-media--inset">Using a <strong>legacy uploaded</strong> file. Paste a YouTube link below to replace it, or remove it with the checkbox.</p>
                                    <p class="evt-muted evt-design-hint evt-design-hint-follow-code">File: <code>{{ $videoBackgroundStored }}</code></p>
                                @endif
                            @endif
                            <input type="hidden" name="clear_video" value="0">
                            <label class="profile-label evt-check-label evt-design-media-remove-toggle">
                                <input type="checkbox" name="clear_video" value="1" class="evt-check-input" @checked(old('clear_video') === '1')>
                                Remove background video
                            </label>
                            <div class="profile-field evt-design-inset-field">
                                <label for="video_background_youtube" class="profile-label evt-design-upload-micro">YouTube link or video ID</label>
                                <input id="video_background_youtube"
                                       name="video_background_youtube"
                                       type="text"
                                       inputmode="url"
                                       autocomplete="off"
                                       maxlength="500"
                                       class="profile-input {{ $errors->has('video_background_youtube') ? 'profile-input--error' : '' }}"
                                       value="{{ $videoYoutubeFieldValue }}"
                                       placeholder="https://www.youtube.com/watch?v=… or youtu.be/…">
                                <p class="evt-muted evt-design-hint evt-design-media-youtube-note">Leave unchanged to keep the current background. Only public or unlisted videos that allow embedding will play for guests.</p>
                                @error('video_background_youtube')
                                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="evt-design-fieldset">
                <legend class="profile-label">Background music</legend>
                <p class="evt-muted evt-design-hint">Optional audio with an explicit play button on the invitation.</p>
                <div class="evt-design-inset-stack">
                    <div class="evt-design-inset-panel">
                        <div class="evt-design-inset-head-row">
                            <span class="evt-design-inset-title">Audio file</span>
                        </div>
                        <div class="evt-design-inset-body">
                            @if (! empty($invitationMerged['effects']['audio_track']))
                                <p class="evt-design-current-media evt-design-current-media--inset">Current audio: <code>{{ $invitationMerged['effects']['audio_track'] }}</code></p>
                            @endif
                            <input type="hidden" name="clear_audio" value="0">
                            <label class="profile-label evt-check-label evt-design-media-remove-toggle">
                                <input type="checkbox" name="clear_audio" value="1" class="evt-check-input" @checked(old('clear_audio') === '1')>
                                Remove music track
                            </label>
                            <div class="profile-field evt-design-inset-field">
                                <label for="audio_track" class="profile-label evt-design-upload-micro">Upload MP3 or OGG</label>
                                <input id="audio_track" name="audio_track" type="file" accept="audio/mpeg,audio/mp3,audio/ogg,audio/wav" class="profile-input evt-design-media-file">
                                @error('audio_track')
                                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
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
