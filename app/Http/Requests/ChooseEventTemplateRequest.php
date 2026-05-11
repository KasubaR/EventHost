<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\UserCanUseInvitationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChooseEventTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && $this->user()->can('update', $event);
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('invitation_template_id');
        $this->merge([
            'invitation_template_id' => $raw === '' || $raw === null ? null : $raw,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invitation_template_id' => [
                'required',
                Rule::exists('invitation_templates', 'id')->where('is_active', true),
                new UserCanUseInvitationTemplate,
            ],
        ];
    }
}
