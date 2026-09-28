<?php

namespace App\Http\Requests;

use App\Models\Guest;
use Illuminate\Foundation\Http\FormRequest;

class RejectRsvpApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Guest $guest */
        $guest = $this->route('guest');
        $guest->loadMissing('event');

        return $this->user() !== null && $this->user()->id === $guest->event->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'host_rejection_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
