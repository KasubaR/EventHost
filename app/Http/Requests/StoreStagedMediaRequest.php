<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\StagedMedia;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStagedMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && $this->user() !== null
            && $this->user()->can('update', $event);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slot = (string) $this->input('slot', '');

        return [
            'slot' => ['required', 'string', Rule::in(StagedMedia::slots())],
            'file' => array_merge(['required'], InvitationMediaRules::rulesForSlot($slot)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large.',
            'file.mimes' => 'That file type is not supported.',
            'file.image' => 'That file is not an image.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Event|null $event */
            $event = $this->route('event');
            if (! $event instanceof Event) {
                return;
            }

            $slot = (string) $this->input('slot');

            // Slots the layout does not have at all are rejected outright — no
            // pending edit in the open form can make them appear.
            $variant = InvitationLayoutVariant::normalize(
                app(InvitationCustomizationService::class)->resolvedTemplate($event)->layout_variant ?? null
            );
            $maxCouple = InvitationLayoutVariant::maxCouplePhotoSlots($variant);

            if ($slot === StagedMedia::SLOT_HERO_PORTRAIT
                && InvitationLayoutVariant::maxInvitationHeroPortraitSlots($variant) === 0) {
                $validator->errors()->add('file', 'This invitation layout does not support a separate hero portrait upload.');

                return;
            }

            if (($slot === StagedMedia::SLOT_COUPLE || StagedMedia::isSpeakerSlot($slot)) && $maxCouple === 0) {
                $validator->errors()->add('file', 'This invitation layout does not support portrait uploads.');

                return;
            }

            if (StagedMedia::isSpeakerSlot($slot) && $variant !== InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
                $validator->errors()->add('file', 'This invitation layout does not use numbered speaker slots.');

                return;
            }

            if ($slot === StagedMedia::SLOT_COUPLE && $variant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
                $validator->errors()->add('file', 'This invitation layout uses numbered speaker slots.');

                return;
            }

            // Multi-value slots are capped on *staged* rows only, deliberately.
            // Counting saved images too would reject the common "remove three, add
            // three" edit, because staging cannot see removals the open form has
            // not submitted yet. The authoritative saved+staged−removed check runs
            // in UpdateInvitationDesignRequest; over-staging here costs nothing but
            // pruned disk.
            $ceiling = match ($slot) {
                StagedMedia::SLOT_GALLERY => InvitationMediaRules::GALLERY_MAX,
                StagedMedia::SLOT_COUPLE => $maxCouple,
                default => null,
            };

            if ($ceiling !== null) {
                $stagedCount = StagedMedia::query()
                    ->ownedBy($event->id, $this->user()->id)
                    ->where('slot', $slot)
                    ->count();

                if ($stagedCount >= $ceiling) {
                    $validator->errors()->add(
                        'file',
                        'You can queue at most '.$ceiling.' image'.($ceiling === 1 ? '' : 's').' at a time here. Save what you have, then add more.'
                    );
                }
            }
        });
    }
}
