<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user() !== null
            && $this->user()->id === $event->user_id
            && $this->user()->canUsePremiumEventTools();
    }

    protected function prepareForValidation(): void
    {
        $label = $this->input('label');
        if (is_string($label)) {
            $t = trim($label);
            $this->merge(['label' => $t === '' ? null : $t]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
        ];
    }
}
