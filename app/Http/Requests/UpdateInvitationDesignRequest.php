<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationFonts;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaRules;
use App\Support\InvitationPalettes;
use App\Support\InvitationSections;
use App\Support\InvitationVideoBackground;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInvitationDesignRequest extends FormRequest
{
    /** Resolved once — withValidator reads it for four separate cap checks. */
    private ?Collection $stagedRows = null;

    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && $this->user()->can('update', $event);
    }

    protected function prepareForValidation(): void
    {
        $visibility = $this->input('section_visible', []);
        $boolVisibility = [];
        if (is_array($visibility)) {
            foreach ($visibility as $key => $value) {
                // Use explicit in_array check so "1"/"true"/true all map consistently
                $boolVisibility[(string) $key] = in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true)
                    || (is_string($value) && strtolower($value) === 'true');
            }
        }

        /** @var Event|null $routeEvent */
        $routeEvent = $this->route('event');

        if ($this->has('countdown_enabled')) {
            $countdownEnabled = $this->boolean('countdown_enabled');
        } else {
            $prev = null;
            if ($routeEvent instanceof Event) {
                $storedEffects = $routeEvent->invitation_customization['effects'] ?? null;
                $prev = is_array($storedEffects) ? ($storedEffects['countdown_enabled'] ?? null) : null;
            }
            $countdownEnabled = $prev !== null ? (bool) $prev : true;
        }

        $rawSchedule = $this->input('schedule_items');
        if (! is_array($rawSchedule)) {
            $rawSchedule = [];
        }
        $scheduleClean = InvitationCustomizationService::normalizeScheduleItems($rawSchedule);

        $story = $this->input('content_story');
        $story = is_string($story) ? Str::limit(trim($story), 12000, '') : '';

        $trimLine = function (string $key, int $max): string {
            $v = $this->input($key);

            return is_string($v) ? Str::limit(trim($v), $max, '') : '';
        };

        $rawSpeakers = $this->input('speaker_cards', []);
        $speakerRows = [];
        if (is_array($rawSpeakers)) {
            foreach (array_slice($rawSpeakers, 0, 4) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $speakerRows[] = [
                    'role' => Str::limit(trim((string) ($row['role'] ?? '')), 80, ''),
                    'name' => Str::limit(trim((string) ($row['name'] ?? '')), 120, ''),
                ];
            }
        }

        $rawRsvpForm = $this->input('rsvp_form', []);
        $normalizedRsvpForm = [];
        if (is_array($rawRsvpForm)) {
            foreach (InvitationCustomizationService::RSVP_FORM_FIELDS as $field) {
                $fieldData = is_array($rawRsvpForm[$field] ?? null) ? $rawRsvpForm[$field] : [];
                $normalizedRsvpForm[$field] = [
                    'visible' => in_array($fieldData['visible'] ?? null, [true, 1, '1', 'true', 'on', 'yes'], true),
                    'label' => Str::limit(trim((string) ($fieldData['label'] ?? '')), 100, ''),
                ];
            }
        }

        $rawSpeakerPhotoClear = $this->input('speaker_photo_clear', []);
        $speakerPhotoClear = [];
        for ($i = 0; $i < 4; $i++) {
            $speakerPhotoClear[$i] = in_array($rawSpeakerPhotoClear[$i] ?? null, [true, 1, '1', 'true', 'on', 'yes'], true);
        }

        $this->merge([
            'section_visible' => $boolVisibility,
            'rsvp_form' => $normalizedRsvpForm,
            'speaker_photo_clear' => $speakerPhotoClear,
            'animation_subtle' => $this->boolean('animation_subtle'),
            'clear_video' => $this->boolean('clear_video'),
            'clear_audio' => $this->boolean('clear_audio'),
            'clear_hero_portrait' => $this->boolean('clear_hero_portrait'),
            'countdown_enabled' => $countdownEnabled,
            'schedule_items' => $scheduleClean,
            'content_story' => $story,
            'speaker_cards' => $speakerRows,
            'venue_note' => $trimLine('venue_note', 500),
            'bfa_conference_theme' => $trimLine('bfa_conference_theme', 160),
            'bfa_dress_code' => $trimLine('bfa_dress_code', 160),
            'bfa_presenter_line' => $trimLine('bfa_presenter_line', 200),
            'bfa_presents_line' => $trimLine('bfa_presents_line', 120),
            'bfa_tagline_bar' => $trimLine('bfa_tagline_bar', 200),
            'bfa_tagline_quote' => $trimLine('bfa_tagline_quote', 300),
            'bfa_host_slot' => (int) ($this->input('bfa_host_slot', 1)),
            'contact_phone_primary' => $trimLine('contact_phone_primary', 40),
            'contact_phone_secondary' => $trimLine('contact_phone_secondary', 40),
            'ei_color_theme' => $trimLine('ei_color_theme', 160),
            'ei_guest_speaker' => $trimLine('ei_guest_speaker', 120),
            'ei_mc' => $trimLine('ei_mc', 120),
            'wi_hero_eyebrow' => $trimLine('wi_hero_eyebrow', 120),
            'wi_couple_caption' => $trimLine('wi_couple_caption', 160),
            'wi_footer_quote' => $trimLine('wi_footer_quote', 300),
            'wi2_hero_tag' => $trimLine('wi2_hero_tag', 120),
            'wi2_invite_formal' => $trimLine('wi2_invite_formal', 160),
            'wi2_invite_body' => $trimLine('wi2_invite_body', 600),
            'wi2_photo_quote' => $trimLine('wi2_photo_quote', 400),
            'wi2_photo_quote_cite' => $trimLine('wi2_photo_quote_cite', 160),
            'wi2_footer_monogram' => $trimLine('wi2_footer_monogram', 24),
            'wi2_footer_legal' => $trimLine('wi2_footer_legal', 120),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable here, then required in withValidator() for every layout that
            // actually consumes the theme variables — Beauty for Ashes does not, so
            // its form omits the picker entirely.
            'theme_palette' => ['nullable', Rule::in(InvitationPalettes::keys())],
            'font_heading_key' => ['required', Rule::in(InvitationFonts::keys())],
            'font_body_key' => ['required', Rule::in(InvitationFonts::keys())],

            'section_order' => ['required', 'array', 'min:1'],
            'section_order.*' => ['required', 'string', Rule::in(InvitationSections::all())],

            'section_visible' => ['nullable', 'array'],
            'section_visible.*' => ['boolean'],

            'animation_subtle' => ['boolean'],
            'countdown_enabled' => ['boolean'],

            'gallery_images' => ['nullable', 'array', 'max:6'],
            'gallery_images.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'gallery_remove' => ['nullable', 'array'],
            'gallery_remove.*' => ['string', 'regex:/^invitation-gallery\/[0-9]+\/[a-zA-Z0-9_\-]+\.(webp|jpe?g|png|gif)$/i'],

            'invitation_hero_portrait' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'clear_hero_portrait' => ['boolean'],
            'couple_photos' => ['nullable', 'array', 'max:4'],
            'couple_photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'couple_remove' => ['nullable', 'array'],
            'couple_remove.*' => ['string', 'regex:/^invitation-couple\/[0-9]+\/[a-zA-Z0-9_\-]+\.(webp|jpe?g|png|gif)$/i'],

            'video_background_youtube' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    if (InvitationVideoBackground::parseVideoId((string) $value) === null) {
                        $fail('Enter a valid YouTube link or video ID.');
                    }
                },
            ],
            'audio_track' => ['nullable', 'file', 'mimes:mp3,mpeg,ogg,wav', 'max:5120'],
            'clear_video' => ['boolean'],
            'clear_audio' => ['boolean'],

            'template_fingerprint' => ['nullable', 'string', 'size:32'],

            'content_story' => ['nullable', 'string', 'max:12000'],
            'speaker_cards' => ['nullable', 'array', 'max:4'],
            'speaker_cards.*.role' => ['nullable', 'string', 'max:80'],
            'speaker_cards.*.name' => ['nullable', 'string', 'max:120'],
            'venue_note' => ['nullable', 'string', 'max:500'],
            'bfa_conference_theme' => ['nullable', 'string', 'max:160'],
            'bfa_dress_code' => ['nullable', 'string', 'max:160'],
            'bfa_presenter_line' => ['nullable', 'string', 'max:200'],
            'bfa_presents_line' => ['nullable', 'string', 'max:120'],
            'bfa_tagline_bar' => ['nullable', 'string', 'max:200'],
            'bfa_tagline_quote' => ['nullable', 'string', 'max:300'],
            'bfa_host_slot' => ['nullable', 'integer', 'min:0', 'max:3'],
            'contact_phone_primary' => ['nullable', 'string', 'max:40'],
            'contact_phone_secondary' => ['nullable', 'string', 'max:40'],
            'ei_color_theme' => ['nullable', 'string', 'max:160'],
            'ei_guest_speaker' => ['nullable', 'string', 'max:120'],
            'ei_mc' => ['nullable', 'string', 'max:120'],
            'wi_hero_eyebrow' => ['nullable', 'string', 'max:120'],
            'wi_couple_caption' => ['nullable', 'string', 'max:160'],
            'wi_footer_quote' => ['nullable', 'string', 'max:300'],
            'wi2_hero_tag' => ['nullable', 'string', 'max:120'],
            'wi2_invite_formal' => ['nullable', 'string', 'max:160'],
            'wi2_invite_body' => ['nullable', 'string', 'max:600'],
            'wi2_photo_quote' => ['nullable', 'string', 'max:400'],
            'wi2_photo_quote_cite' => ['nullable', 'string', 'max:160'],
            'wi2_footer_monogram' => ['nullable', 'string', 'max:24'],
            'wi2_footer_legal' => ['nullable', 'string', 'max:120'],
            'schedule_items' => ['present', 'array', 'max:24'],
            'schedule_items.*.time' => ['nullable', 'string', 'max:48'],
            'schedule_items.*.title' => ['nullable', 'string', 'max:160'],
            'schedule_items.*.detail' => ['nullable', 'string', 'max:500'],

            'rsvp_form' => ['required', 'array'],
            'rsvp_form.*.visible' => ['required', 'boolean'],
            'rsvp_form.*.label' => ['required', 'string', 'max:100'],

            'speaker_photo' => ['nullable', 'array', 'max:4'],
            'speaker_photo.*' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'speaker_photo_clear' => ['nullable', 'array', 'max:4'],
            'speaker_photo_clear.*' => ['boolean'],

            // Ids from EventInvitationMediaController. The rows themselves are
            // re-checked against event and user when they are consumed; anything
            // that does not resolve is silently ignored rather than fatal, because
            // a stale id usually means a save already claimed it.
            'staged_media' => ['nullable', 'array', 'max:24'],
            'staged_media.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * Staged rows named by this submission, scoped to the event and the user.
     *
     * @return Collection<int, StagedMedia>
     */
    private function stagedRows(): Collection
    {
        if ($this->stagedRows !== null) {
            return $this->stagedRows;
        }

        $event = $this->route('event');
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $this->input('staged_media', [])
        ))));

        if ($ids === [] || ! $event instanceof Event || $this->user() === null) {
            return $this->stagedRows = collect();
        }

        return $this->stagedRows = StagedMedia::query()
            ->ownedBy($event->id, $this->user()->id)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @return Collection<int, StagedMedia>
     */
    private function stagedForSlot(string $slot): Collection
    {
        return $this->stagedRows()->where('slot', $slot)->values();
    }

    /**
     * A palette is only safe on a template whose stylesheet shares its light/dark
     * polarity — layout CSS derives its local tokens by mixing toward `white` or
     * toward the background, so crossing the two produces unreadable text.
     */
    protected function validatePalette(Validator $validator, InvitationTemplate $template): void
    {
        $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);

        // This layout hardcodes its colours and ignores the theme variables, so its
        // form omits the picker and there is nothing to validate.
        if ($variant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
            return;
        }

        $key = $this->input('theme_palette');

        // Palette selection is a Pro+ feature. The form disables the radios and
        // submits nothing for everyone else, so a non-empty value here only
        // happens by posting the form directly — reject it rather than silently
        // applying it. Absence is fine: the controller keeps the template's
        // default theme when theme_palette is missing.
        if (! $this->user()->canChooseInvitationPalette()) {
            if ($key !== null && $key !== '') {
                $validator->errors()->add('theme_palette', 'Choosing a colour palette requires the Pro+ plan.');
            }

            return;
        }

        $palette = is_string($key) ? InvitationPalettes::get($key) : null;

        if ($palette === null) {
            $validator->errors()->add('theme_palette', 'Choose a colour palette for this invitation.');

            return;
        }

        $templateMode = InvitationPalettes::modeForBackground(
            (string) ($template->default_theme['background'] ?? '#ffffff')
        );

        if ($palette['mode'] !== $templateMode) {
            $validator->errors()->add(
                'theme_palette',
                'That colour palette is not available for this template.'
            );
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Event|null $event */
            $event = $this->route('event');
            if (! $event instanceof Event) {
                return;
            }

            $template = InvitationTemplate::query()
                ->where('id', $event->invitation_template_id)
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                $template = InvitationTemplate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
            }

            if ($template === null) {
                $validator->errors()->add('section_order', 'No active invitation templates are available.');

                return;
            }

            $this->validatePalette($validator, $template);

            $allowed = collect($template->default_sections ?? [])->pluck('type')->values()->all();
            $order = $this->input('section_order', []);

            $allowedSorted = $allowed;
            $orderSorted = $order;
            sort($allowedSorted);
            sort($orderSorted);

            if ($allowedSorted !== $orderSorted || count($order) !== count(array_unique($order))) {
                $service = app(InvitationCustomizationService::class);
                $currentFingerprint = $service->templateFingerprint($template);
                $submittedFingerprint = $this->input('template_fingerprint', '');

                $message = ($submittedFingerprint !== '' && $submittedFingerprint !== $currentFingerprint)
                    ? 'The invitation template changed since you opened this form. Reload the page and try again.'
                    : 'The selected sections do not match the required layout for this invitation.';

                $validator->errors()->add('section_order', $message);
            }

            $existingGallery = $event->invitation_customization['media']['gallery'] ?? [];
            $removeSet = array_values(array_unique($this->input('gallery_remove', []) ?: []));
            foreach ($removeSet as $path) {
                if (! in_array($path, $existingGallery, true)) {
                    $validator->errors()->add('gallery_remove', 'Invalid gallery image reference.');
                    break;
                }
            }

            $galleryKeep = array_values(array_diff($existingGallery, $removeSet));

            $galleryPrefix = 'invitation-gallery/'.$event->id.'/';
            foreach ($galleryKeep as $path) {
                if (! str_starts_with((string) $path, $galleryPrefix)) {
                    $validator->errors()->add('gallery_remove', 'Invalid gallery image reference.');
                    break;
                }
            }

            $stagedGallery = $this->stagedForSlot(StagedMedia::SLOT_GALLERY);

            $keepCount = count($galleryKeep);
            // Staged files are already on disk but not yet referenced, so they count
            // towards the cap here — this is the authoritative check, the one at
            // staging time only sees files, never pending removals.
            $newCount = count($this->file('gallery_images', []) ?: []) + $stagedGallery->count();
            if ($keepCount + $newCount > InvitationMediaRules::GALLERY_MAX) {
                $validator->errors()->add('gallery_images', 'You may keep at most six gallery images.');
            }

            $cap = (int) config('invitations.gallery_max_total_bytes', 0);
            if ($cap > 0 && ! $validator->errors()->has('gallery_images')) {
                $disk = Storage::disk('public');
                $totalBytes = 0;
                foreach ($galleryKeep as $path) {
                    $pathStr = (string) $path;
                    if ($disk->exists($pathStr)) {
                        $totalBytes += $disk->size($pathStr);
                    }
                }
                foreach ($this->file('gallery_images', []) ?: [] as $file) {
                    if ($file instanceof UploadedFile) {
                        $totalBytes += $file->getSize();
                    }
                }
                $totalBytes += (int) $stagedGallery->sum('bytes');

                if ($totalBytes > $cap) {
                    $validator->errors()->add('gallery_images', 'Total gallery size exceeds the allowed limit.');
                }
            }

            $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);
            $maxPortrait = InvitationLayoutVariant::maxInvitationHeroPortraitSlots($variant);
            $maxCouple = InvitationLayoutVariant::maxCouplePhotoSlots($variant);

            if ($maxPortrait === 0
                && ($this->hasFile('invitation_hero_portrait')
                    || $this->stagedForSlot(StagedMedia::SLOT_HERO_PORTRAIT)->isNotEmpty())) {
                $validator->errors()->add('invitation_hero_portrait', 'This invitation layout does not support a separate hero portrait upload.');
            }

            if ($maxCouple === 0) {
                $coupleFiles = $this->file('couple_photos', []) ?: [];
                $hasCoupleUpload = $this->stagedForSlot(StagedMedia::SLOT_COUPLE)->isNotEmpty();
                foreach ($coupleFiles as $file) {
                    if ($file instanceof UploadedFile) {
                        $hasCoupleUpload = true;
                        break;
                    }
                }
                for ($i = 0; $i < 4 && ! $hasCoupleUpload; $i++) {
                    $hasCoupleUpload = $this->stagedForSlot(StagedMedia::speakerSlot($i))->isNotEmpty();
                }
                if ($hasCoupleUpload) {
                    $validator->errors()->add('couple_photos', 'This invitation layout does not support couple photo uploads.');
                }
                $coupleRemoveRaw = $this->input('couple_remove', []) ?: [];
                if ($coupleRemoveRaw !== []) {
                    $validator->errors()->add('couple_remove', 'Invalid couple photo reference.');
                }
            } else {
                $existingCouple = $event->invitation_customization['media']['couple_photos'] ?? [];
                $existingCouple = is_array($existingCouple) ? array_values(array_unique(array_map('strval', $existingCouple))) : [];
                $removeCouple = array_values(array_unique($this->input('couple_remove', []) ?: []));
                foreach ($removeCouple as $path) {
                    if (! in_array($path, $existingCouple, true)) {
                        $validator->errors()->add('couple_remove', 'Invalid couple photo reference.');
                        break;
                    }
                }

                $coupleKeep = array_values(array_diff($existingCouple, $removeCouple));

                // Only the batch slot is counted. Beauty for Ashes addresses its four
                // portraits positionally (speaker:0…3), where a staged file replaces
                // one slot rather than adding to the total — counting those here would
                // reject replacing a portrait on a layout whose slots are all full.
                $newCoupleCount = $this->stagedForSlot(StagedMedia::SLOT_COUPLE)->count();
                foreach ($this->file('couple_photos', []) ?: [] as $file) {
                    if ($file instanceof UploadedFile) {
                        $newCoupleCount++;
                    }
                }
                if (count($coupleKeep) + $newCoupleCount > $maxCouple) {
                    $validator->errors()->add('couple_photos', 'You may keep at most '.$maxCouple.' couple photo(s) for this layout.');
                }
            }
        });
    }
}
