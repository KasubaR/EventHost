<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationFonts;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationSections;
use App\Support\InvitationVideoBackground;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInvitationDesignRequest extends FormRequest
{
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

        // Normalize hex colors to lowercase so downstream JS comparisons are consistent
        foreach (['theme_primary', 'theme_accent', 'theme_background'] as $field) {
            $val = $this->input($field);
            if (is_string($val)) {
                $this->merge([$field => strtolower($val)]);
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theme_primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_heading_key' => ['required', Rule::in(InvitationFonts::keys())],
            'font_body_key' => ['required', Rule::in(InvitationFonts::keys())],

            'section_order' => ['required', 'array', 'min:1'],
            'section_order.*' => ['required', 'string', Rule::in(InvitationSections::all())],

            'section_visible' => ['nullable', 'array'],
            'section_visible.*' => ['boolean'],

            'animation_subtle' => ['boolean'],
            'countdown_enabled' => ['boolean'],

            'gallery_images' => ['nullable', 'array', 'max:5'],
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
        ];
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

            $keepCount = count($galleryKeep);
            $newCount = count($this->file('gallery_images', []) ?: []);
            if ($keepCount + $newCount > 5) {
                $validator->errors()->add('gallery_images', 'You may keep at most five gallery images.');
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
                if ($totalBytes > $cap) {
                    $validator->errors()->add('gallery_images', 'Total gallery size exceeds the allowed limit.');
                }
            }

            $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);
            $maxPortrait = InvitationLayoutVariant::maxInvitationHeroPortraitSlots($variant);
            $maxCouple = InvitationLayoutVariant::maxCouplePhotoSlots($variant);

            if ($maxPortrait === 0 && $this->hasFile('invitation_hero_portrait')) {
                $validator->errors()->add('invitation_hero_portrait', 'This invitation layout does not support a separate hero portrait upload.');
            }

            if ($maxCouple === 0) {
                $coupleFiles = $this->file('couple_photos', []) ?: [];
                $hasCoupleUpload = false;
                foreach ($coupleFiles as $file) {
                    if ($file instanceof UploadedFile) {
                        $hasCoupleUpload = true;
                        break;
                    }
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
                $newCoupleCount = 0;
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
