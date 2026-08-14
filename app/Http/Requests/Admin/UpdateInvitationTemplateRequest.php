<?php

namespace App\Http\Requests\Admin;

use App\Models\InvitationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateInvitationTemplateRequest extends FormRequest
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
            'is_featured' => ['required', 'boolean'],
            'featured_sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'preview_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ];
    }

    /**
     * A featured template with no image would render an empty card on the
     * homepage, so refuse the combination at the door.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('is_featured') || $this->hasFile('preview_image')) {
                    return;
                }

                $template = $this->route('invitation_template');
                $existing = $template instanceof InvitationTemplate ? $template->preview_image : null;

                if ($existing === null || $existing === '') {
                    $name = $template instanceof InvitationTemplate ? $template->name : 'This template';

                    $validator->errors()->add(
                        'preview_image',
                        'Upload a preview image for "'.$name.'" before featuring it on the homepage.'
                    );
                }
            },
        ];
    }
}
