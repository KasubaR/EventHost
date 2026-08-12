<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, unauthenticated request — anyone who scanned the table QR can upload.
 * Business-logic gates (photo wall live, plan tier) are checked in the controller,
 * not here, since a rejected upload still needs a friendly page response, not a 403.
 */
class StoreTablePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('uploader_name');
        if (is_string($name)) {
            $t = trim($name);
            $this->merge(['uploader_name' => $t === '' ? null : $t]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'uploader_name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
