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
     * JSON picks (media-uploader.js) send `file`; a no-JS form post still
     * sends `hero_image`. Same crop rules either way.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $field = $this->hasFile('file') || $this->wantsJson() ? 'file' : 'hero_image';

        return [
            $field => array_merge(['required'], InvitationMediaRules::coverRules()),
        ];
    }
}
