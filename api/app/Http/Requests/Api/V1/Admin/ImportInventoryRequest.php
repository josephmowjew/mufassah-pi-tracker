<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Spreadsheet\SpreadsheetReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Validates a stock-import upload.
 *
 * The file type is checked against the *client* extension rather than the
 * detected MIME type on purpose. A CSV is reported as `text/plain` by some
 * browsers and `application/vnd.ms-excel` by others, so a MIME whitelist
 * rejects perfectly good files on some machines and accepts renamed binaries on
 * others. The extension is what decides the parser anyway, and the parser is
 * what validates the content.
 */
class ImportInventoryRequest extends FormRequest
{
    /** 5 MB — a 2000-row sheet is well under this. */
    private const MAX_KILOBYTES = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
            // Optional: a single-branch operator can leave the column blank.
            // Null for a super admin means "rows must name their own branch".
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the spreadsheet you want to upload.',
            'file.file' => 'The upload did not arrive as a file. Try again.',
            'file.max' => 'The file is larger than 5 MB. Split it into smaller sheets.',
            'branch_id.exists' => 'The selected branch no longer exists.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $file = $this->file('file');

                if (! $file instanceof UploadedFile) {
                    return;
                }

                $extension = SpreadsheetReader::extensionOf($file->getClientOriginalName());

                if (! SpreadsheetReader::supports($extension)) {
                    $validator->errors()->add(
                        'file',
                        'Upload a .csv or .xlsx file. If Excel saved it as .xls, use "Save As" and choose CSV or Excel Workbook.'
                    );

                    return;
                }

                if (! $file->isValid()) {
                    $validator->errors()->add('file', 'The upload failed before it reached the server. Try again.');
                }
            },
        ];
    }
}
