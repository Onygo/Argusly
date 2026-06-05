<?php

namespace App\Services\WorkspaceIntelligence;

use Illuminate\Support\Arr;

class ProfileInputNormalizationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeOrganizationInput(array $payload): array
    {
        $sourceType = trim((string) ($payload['source_type'] ?? 'manual_text'));

        return [
            'source_type' => $sourceType,
            'source_payload' => $this->normalizeSourcePayload($sourceType, $payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePersonaInput(array $payload): array
    {
        return $this->normalizeOrganizationInput($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeTeamMemberInput(array $payload): array
    {
        $sourceType = trim((string) ($payload['source_type'] ?? 'pasted_profile_text'));

        return [
            'source_type' => $sourceType,
            'source_payload' => $this->normalizeSourcePayload($sourceType, $payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSourcePayload(string $sourceType, array $payload): array
    {
        $websiteUrl = trim((string) ($payload['website_url'] ?? ''));
        $companyName = trim((string) ($payload['company_name'] ?? ''));
        $industry = trim((string) ($payload['industry'] ?? ''));
        $manualText = $this->normalizeText((string) ($payload['manual_text'] ?? ''));
        $profileText = $this->normalizeText((string) ($payload['pasted_profile_text'] ?? $payload['uploaded_bio_text'] ?? ''));
        $linkedinReferenceUrl = trim((string) ($payload['linkedin_reference_url'] ?? ''));

        return array_filter([
            'website_url' => $websiteUrl !== '' ? $websiteUrl : null,
            'company_name' => $companyName !== '' ? $companyName : null,
            'industry' => $industry !== '' ? $industry : null,
            'manual_text' => $manualText !== '' ? $manualText : null,
            'pasted_profile_text' => $profileText !== '' ? $profileText : null,
            'linkedin_reference_url' => $linkedinReferenceUrl !== '' ? $linkedinReferenceUrl : null,
            'input_text' => $this->buildInputText($sourceType, [
                'website_url' => $websiteUrl,
                'company_name' => $companyName,
                'industry' => $industry,
                'manual_text' => $manualText,
                'pasted_profile_text' => $profileText,
                'linkedin_reference_url' => $linkedinReferenceUrl,
            ]),
        ], fn ($value) => ! is_null($value));
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function buildInputText(string $sourceType, array $payload): string
    {
        return match ($sourceType) {
            'company_name_and_industry' => trim(collect([
                $payload['company_name'] !== '' ? 'Company: ' . $payload['company_name'] : null,
                $payload['industry'] !== '' ? 'Industry: ' . $payload['industry'] : null,
            ])->filter()->implode("\n")),
            'pasted_profile_text', 'uploaded_bio_text' => $payload['pasted_profile_text'],
            'linkedin_reference_url' => trim(collect([
                $payload['pasted_profile_text'] !== '' ? $payload['pasted_profile_text'] : null,
                $payload['linkedin_reference_url'] !== '' ? 'LinkedIn reference: ' . $payload['linkedin_reference_url'] : null,
            ])->filter()->implode("\n\n")),
            default => Arr::first([
                $payload['manual_text'],
                $payload['pasted_profile_text'],
                $payload['website_url'],
            ], fn ($value) => trim((string) $value) !== '', '') ?? '',
        };
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\R{3,}/', "\n\n", $value) ?? $value);
    }
}
