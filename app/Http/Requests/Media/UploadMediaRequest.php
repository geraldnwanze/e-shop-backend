<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadMediaRequest extends FormRequest
{
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
            'file' => $this->fileRules(config('media.allowed_extensions'), config('media.allowed_mimes')),
            'folder' => ['nullable', 'string', Rule::in(['uploads', 'products', 'categories', 'branding'])],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Extension AND real (content-sniffed) MIME must both be allowed; SVG and
     * other active formats are excluded. Dimensions are capped against decompression bombs.
     *
     * @param  list<string>  $extensions
     * @param  list<string>  $mimes
     * @return array<int, mixed>
     */
    protected function fileRules(array $extensions, array $mimes): array
    {
        return [
            'required', 'file',
            'max:'.config('media.max_upload_kb'),
            'extensions:'.implode(',', $extensions),
            'mimetypes:'.implode(',', $mimes),
            'dimensions:max_width='.config('media.max_dimension').',max_height='.config('media.max_dimension'),
        ];
    }
}
