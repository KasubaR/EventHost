<?php

namespace App\Support;

use App\Services\InvitationCustomizationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InvitationCustomizationPersistenceValidator
{
    private const MEDIA_PATH_PATTERN = '#^invitation-media/[0-9]+/[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]{1,12}$#';

    /**
     * Ensure payload matches the persistence shape for invitation_customization (schema guards).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public static function validate(array $payload): void
    {
        Validator::make($payload, [
            'schema_version' => ['required', 'integer', Rule::in([InvitationCustomizationService::CURRENT_SCHEMA_VERSION])],

            'content' => ['required', 'array'],
            'content.story' => ['nullable', 'string', 'max:12000'],
            'content.speaker_cards' => ['nullable', 'array', 'max:4'],
            'content.speaker_cards.*.role' => ['nullable', 'string', 'max:80'],
            'content.speaker_cards.*.name' => ['nullable', 'string', 'max:120'],
            'content.venue_note' => ['nullable', 'string', 'max:500'],
            'content.bfa_conference_theme' => ['nullable', 'string', 'max:160'],
            'content.bfa_dress_code' => ['nullable', 'string', 'max:160'],
            'content.bfa_presenter_line' => ['nullable', 'string', 'max:200'],
            'content.bfa_presents_line' => ['nullable', 'string', 'max:120'],
            'content.bfa_tagline_bar' => ['nullable', 'string', 'max:200'],
            'content.contact_phone_primary' => ['nullable', 'string', 'max:40'],
            'content.contact_phone_secondary' => ['nullable', 'string', 'max:40'],
            'content.ei_color_theme' => ['nullable', 'string', 'max:160'],
            'content.ei_guest_speaker' => ['nullable', 'string', 'max:120'],
            'content.ei_mc' => ['nullable', 'string', 'max:120'],
            'content.wi_hero_eyebrow' => ['nullable', 'string', 'max:120'],
            'content.wi_couple_caption' => ['nullable', 'string', 'max:160'],
            'content.wi_footer_quote' => ['nullable', 'string', 'max:300'],
            'content.wi2_hero_tag' => ['nullable', 'string', 'max:120'],
            'content.wi2_invite_formal' => ['nullable', 'string', 'max:160'],
            'content.wi2_invite_body' => ['nullable', 'string', 'max:600'],
            'content.wi2_photo_quote' => ['nullable', 'string', 'max:400'],
            'content.wi2_photo_quote_cite' => ['nullable', 'string', 'max:160'],
            'content.wi2_footer_monogram' => ['nullable', 'string', 'max:24'],
            'content.wi2_footer_legal' => ['nullable', 'string', 'max:120'],
            'content.schedule' => ['present', 'array', 'max:24'],
            'content.schedule.*.time' => ['nullable', 'string', 'max:48'],
            'content.schedule.*.title' => ['required', 'string', 'max:160'],
            'content.schedule.*.detail' => ['nullable', 'string', 'max:500'],

            'theme' => ['required', 'array'],
            'theme.primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.font_heading_key' => ['required', Rule::in(InvitationFonts::keys())],
            'theme.font_body_key' => ['required', Rule::in(InvitationFonts::keys())],

            'sections' => ['required', 'array', 'max:32'],
            'sections.*.type' => ['required', 'string', Rule::in(InvitationSections::all())],
            'sections.*.visible' => ['required', 'boolean'],

            'media' => ['required', 'array'],
            'media.gallery' => ['present', 'array', 'max:6'],
            'media.gallery.*' => ['required', 'string', 'regex:#^invitation-gallery/[0-9]+/[a-zA-Z0-9_\-]+\.(webp|jpe?g|png|gif)$#i'],
            'media.hero_portrait' => ['nullable', 'string', 'regex:#^invitation-hero/[0-9]+/[a-zA-Z0-9_\-]+\.(webp|jpe?g|png|gif)$#i'],
            'media.couple_photos' => ['present', 'array', 'max:4'],
            'media.couple_photos.*' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    $fail('Invalid couple photo value.');

                    return;
                }
                // Empty string is allowed — it marks an unfilled speaker slot in the BFA layout.
                if ($value === '') {
                    return;
                }
                if (! preg_match('#^invitation-couple/[0-9]+/[a-zA-Z0-9_\-]+\.(webp|jpe?g|png|gif)$#i', $value)) {
                    $fail('Invalid couple photo path.');
                }
            }],

            'effects' => ['required', 'array'],
            'effects.animation_subtle' => ['required', 'boolean'],
            'effects.countdown_enabled' => ['required', 'boolean'],
            'effects.video_background' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! is_string($value)) {
                    $fail('Invalid video background value.');

                    return;
                }
                if (InvitationVideoBackground::isYoutube($value)) {
                    return;
                }
                $rule = self::mediaPathRule('video');
                $rule($attribute, $value, $fail);
            }],
            'effects.audio_track' => ['nullable', self::mediaPathRule('audio')],

            'rsvp_form' => ['required', 'array'],
            'rsvp_form.*.visible' => ['required', 'boolean'],
            'rsvp_form.*.label' => ['required', 'string', 'max:100'],
        ])->validate();
    }

    private static function mediaPathRule(string $kind): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($kind): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! is_string($value) || ! preg_match(self::MEDIA_PATH_PATTERN, $value)) {
                $fail("Invalid {$kind} storage path.");
            }
        };
    }
}
