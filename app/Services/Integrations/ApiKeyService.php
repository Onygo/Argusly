<?php

namespace App\Services\Integrations;

use App\Models\ApiKey;
use App\Models\Workspace;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * @param  array<int, string>  $scopes
     * @return array{model: ApiKey, plain_text_key: string}
     */
    public function create(
        Workspace $workspace,
        string $name,
        array $scopes,
        ?string $contentDestinationId = null,
        ?int $createdBy = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $plainText = $this->generatePlainTextKey();
        $keyPrefix = substr($plainText, 0, 14);

        $model = ApiKey::query()->create([
            'workspace_id' => $workspace->id,
            'content_destination_id' => $contentDestinationId,
            'origin_type' => null,
            'origin_id' => null,
            'origin_label' => null,
            'is_legacy_import' => false,
            'managed_via' => ApiKey::MANAGED_VIA_WORKSPACE,
            'notes' => null,
            'name' => $name,
            'key_prefix' => $keyPrefix,
            'key_hash' => hash('sha256', $plainText),
            'scopes' => array_values(array_unique($scopes)),
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);

        return [
            'model' => $model,
            'plain_text_key' => $plainText,
        ];
    }

    public function resolveActiveFromPlainText(string $plainText): ?ApiKey
    {
        $hash = hash('sha256', trim($plainText));

        return ApiKey::query()
            ->with(['workspace.organization', 'contentDestination'])
            ->where('key_hash', $hash)
            ->where(function ($query): void {
                $query->whereNull('is_legacy_import')
                    ->orWhere('is_legacy_import', false);
            })
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function revoke(ApiKey $apiKey): void
    {
        $apiKey->revoked_at = now();
        $apiKey->save();
    }

    private function generatePlainTextKey(): string
    {
        return 'plk_ws_'.Str::random(56);
    }
}
