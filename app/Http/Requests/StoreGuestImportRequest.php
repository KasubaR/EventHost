<?php

namespace App\Http\Requests;

use App\Imports\GuestImportFile;
use App\Imports\GuestImportFileException;
use App\Models\Event;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use LogicException;

class StoreGuestImportRequest extends FormRequest
{
    public const TYPE_MESSAGE = 'Upload a CSV or Excel file (.csv, .xlsx or .xls).';

    private ?GuestImportFile $inspection = null;

    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user() !== null && $this->user()->id === $event->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'bail',
                'required',
                'file',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->getSize() === 0) {
                        $fail(GuestImportFile::EMPTY_MESSAGE);
                    }
                },
                'max:5120',
                'mimes:csv,txt,xlsx,xls',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV or Excel file to import.',
            'file.uploaded' => 'The upload did not finish. Check your connection and choose the file again.',
            'file.file' => 'The upload did not finish. Check your connection and choose the file again.',
            'file.max' => 'This file is larger than 5 MB. Split it into smaller files and import each one.',
            'file.mimes' => self::TYPE_MESSAGE,
        ];
    }

    /**
     * Opens the file once the basic rules pass: headings, row count and (for .xlsx) unzipped size are checked before
     * any guest is written, and the result tells the controller whether to import now or in the background.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var UploadedFile $file */
                $file = $this->file('file');

                $readerType = GuestImportFile::readerTypeFor($file->guessExtension())
                    ?? GuestImportFile::readerTypeFor($file->getClientOriginalExtension());

                if ($readerType === null) {
                    $validator->errors()->add('file', self::TYPE_MESSAGE);

                    return;
                }

                try {
                    $inspection = GuestImportFile::inspect((string) $file->getRealPath(), $readerType);
                } catch (GuestImportFileException $e) {
                    $validator->errors()->add('file', $e->getMessage());

                    return;
                }

                if (($problem = $inspection->problem()) !== null) {
                    $validator->errors()->add('file', $problem);

                    return;
                }

                $this->inspection = $inspection;
            },
        ];
    }

    public function inspection(): GuestImportFile
    {
        return $this->inspection ?? throw new LogicException('The import file is read during validation.');
    }
}
