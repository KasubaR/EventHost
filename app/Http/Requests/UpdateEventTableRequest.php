<?php

namespace App\Http\Requests;

use App\Models\EventTable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EventTable $table */
        $table = $this->route('table');
        $table->loadMissing('event');

        return $this->user() !== null
            && $this->user()->id === $table->event->user_id
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
