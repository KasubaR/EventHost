<?php

namespace App\Http\Requests\Admin;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                Report::STATUS_PENDING,
                Report::STATUS_REVIEWED,
                Report::STATUS_RESOLVED,
                Report::STATUS_DISMISSED,
            ])],
        ];
    }
}
