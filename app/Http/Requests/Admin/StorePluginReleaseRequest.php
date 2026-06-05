<?php

namespace App\Http\Requests\Admin;

use App\Services\PluginUpdates\PluginArchiveUploadService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorePluginReleaseRequest extends FormRequest
{
    private ?string $uploadDiagnosis = null;

    public function authorize(): bool
    {
        return Gate::allows('admin-area-superadmin');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:50', Rule::unique('plugin_releases', 'version')],
            'min_wp_version' => ['nullable', 'string', 'max:40'],
            'tested_wp_version' => ['nullable', 'string', 'max:40'],
            'is_security_release' => ['nullable', 'boolean'],
            'archive' => ['required', 'file', 'mimes:zip', 'max:' . PluginArchiveUploadService::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxSizeMB = PluginArchiveUploadService::MAX_FILE_SIZE_KB / 1024;

        return [
            'version.required' => 'Version number is required.',
            'version.unique' => 'This version has already been uploaded.',
            'archive.required' => 'Please select a ZIP file to upload.',
            'archive.file' => 'The uploaded file is invalid.',
            'archive.mimes' => 'Only ZIP files are allowed.',
            'archive.max' => "File is too large. Maximum size is {$maxSizeMB}MB.",
            'archive.uploaded' => $this->getUploadedErrorMessage(),
        ];
    }

    /**
     * Prepare the request for validation and run diagnostics.
     */
    protected function prepareForValidation(): void
    {
        $uploadService = app(PluginArchiveUploadService::class);
        $analysis = $uploadService->analyzeUploadRequest($this);

        Log::info('PluginReleaseUpload: Request received', [
            'server_limits' => $analysis['server_limits'],
            'request_info' => $analysis['request_info'],
            'user_id' => $this->user()?->id,
            'ip' => $this->ip(),
        ]);

        if (! $analysis['can_proceed']) {
            $this->uploadDiagnosis = $analysis['diagnosis'];

            Log::warning('PluginReleaseUpload: Pre-validation diagnosis failed', [
                'diagnosis' => $this->uploadDiagnosis,
            ]);
        }
    }

    /**
     * Generate a helpful error message for the 'uploaded' validation failure.
     */
    private function getUploadedErrorMessage(): string
    {
        if ($this->uploadDiagnosis !== null) {
            return $this->uploadDiagnosis;
        }

        return 'Upload failed. This usually means the file was blocked by server configuration (nginx client_max_body_size or PHP upload_max_filesize). Contact your server administrator.';
    }

    /**
     * Handle a failed validation attempt with enhanced logging.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();

        Log::warning('PluginReleaseUpload: Validation failed', [
            'errors' => $errors,
            'input_keys' => array_keys($this->all()),
            'has_file' => $this->hasFile('archive'),
            'diagnosis' => $this->uploadDiagnosis,
        ]);

        // If we have a pre-validation diagnosis and the archive failed,
        // replace the generic error with our diagnostic message
        if ($this->uploadDiagnosis !== null && isset($errors['archive'])) {
            $validator->errors()->forget('archive');
            $validator->errors()->add('archive', $this->uploadDiagnosis);
        }

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
