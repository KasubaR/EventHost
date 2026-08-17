<?php

namespace App\Http\Requests\Admin;

use App\Support\InvitationMediaRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketedHeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hero_image' => array_merge(['required'], InvitationMediaRules::coverRules()),
        ];
    }
}
