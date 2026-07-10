<?php

namespace App\Services\DataConnectors\Normalization;

use App\Data\Connectors\NormalizedRecord;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\Normalized\NormalizedAd;
use App\Models\Connectors\Normalized\NormalizedAdGroup;
use App\Models\Connectors\Normalized\NormalizedCampaign;
use App\Models\Connectors\Normalized\NormalizedCrmActivity;
use App\Models\Connectors\Normalized\NormalizedCrmCompany;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Connectors\Normalized\NormalizedMarketingAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NormalizedRecordWriter
{
    /**
     * @param  array<int, NormalizedRecord>  $records
     * @return array{written: int, entity_counts: array<string, int>}
     */
    public function write(ConnectorRawRecord $rawRecord, array $records): array
    {
        $written = 0;
        $entityCounts = [];

        foreach ($records as $record) {
            $this->writeOne($rawRecord, $record);
            $written++;
            $entityCounts[$record->entityType] = ($entityCounts[$record->entityType] ?? 0) + 1;
        }

        return ['written' => $written, 'entity_counts' => $entityCounts];
    }

    private function writeOne(ConnectorRawRecord $rawRecord, NormalizedRecord $record): Model
    {
        $attributes = $this->baseAttributes($rawRecord, $record);

        return match ($record->entityType) {
            NormalizedRecord::MARKETING_ACCOUNT => $this->upsertMarketingAccount($attributes),
            NormalizedRecord::CAMPAIGN => $this->upsertCampaign($rawRecord, $attributes),
            NormalizedRecord::AD_GROUP => $this->upsertAdGroup($rawRecord, $attributes),
            NormalizedRecord::AD => $this->upsertAd($rawRecord, $attributes),
            NormalizedRecord::DAILY_PERFORMANCE => $this->upsertDailyPerformance($attributes),
            NormalizedRecord::CRM_COMPANY => $this->upsertCrmCompany($attributes),
            NormalizedRecord::CRM_CONTACT => $this->upsertCrmContact($rawRecord, $attributes),
            NormalizedRecord::CRM_DEAL => $this->upsertCrmDeal($rawRecord, $attributes),
            NormalizedRecord::CRM_ACTIVITY => $this->upsertCrmActivity($rawRecord, $attributes),
            default => throw new InvalidArgumentException("Unsupported normalized entity type [{$record->entityType}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(ConnectorRawRecord $rawRecord, NormalizedRecord $record): array
    {
        $attributes = array_merge($record->attributes, [
            'workspace_id' => $rawRecord->workspace_id,
            'connector_account_id' => $rawRecord->connector_account_id,
            'provider' => $rawRecord->provider_key,
            'raw_reference' => array_merge($record->rawReference, [
                'connector_raw_record_id' => (string) $rawRecord->id,
                'connector_sync_run_id' => $rawRecord->connector_sync_run_id ? (string) $rawRecord->connector_sync_run_id : null,
                'connector_dataset_id' => $rawRecord->connector_dataset_id ? (string) $rawRecord->connector_dataset_id : null,
                'dataset_key' => $rawRecord->dataset_key,
                'record_type' => $rawRecord->record_type,
                'external_record_id' => $rawRecord->external_record_id,
            ]),
        ]);

        foreach (array_keys($attributes) as $key) {
            if (str_contains(strtolower((string) $key), 'email') && $key !== 'email_hash') {
                unset($attributes[$key]);
            }
        }

        return Arr::where($attributes, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertMarketingAccount(array $attributes): NormalizedMarketingAccount
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_account_id']);

        return $this->upsertModel(
            NormalizedMarketingAccount::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_account_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCampaign(ConnectorRawRecord $rawRecord, array $attributes): NormalizedCampaign
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_campaign_id']);

        $attributes['account_id'] = $attributes['account_id'] ?? $this->marketingAccountId(
            $rawRecord,
            (string) ($attributes['_provider_account_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedCampaign::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_campaign_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertAdGroup(ConnectorRawRecord $rawRecord, array $attributes): NormalizedAdGroup
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_ad_group_id']);

        $attributes['campaign_id'] = $attributes['campaign_id'] ?? $this->campaignId(
            $rawRecord,
            (string) ($attributes['_provider_campaign_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedAdGroup::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_ad_group_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertAd(ConnectorRawRecord $rawRecord, array $attributes): NormalizedAd
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_ad_id']);

        $attributes['campaign_id'] = $attributes['campaign_id'] ?? $this->campaignId(
            $rawRecord,
            (string) ($attributes['_provider_campaign_id'] ?? ''),
        );
        $attributes['ad_group_id'] = $attributes['ad_group_id'] ?? $this->adGroupId(
            $rawRecord,
            (string) ($attributes['_provider_ad_group_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedAd::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_ad_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertDailyPerformance(array $attributes): NormalizedDailyPerformance
    {
        if (isset($attributes['date'])) {
            $attributes['date'] = Carbon::parse($attributes['date'])->startOfDay()->toDateTimeString();
        }

        $this->requireKeys($attributes, ['workspace_id', 'provider', 'entity_type', 'entity_id', 'date']);

        return $this->upsertModel(
            NormalizedDailyPerformance::class,
            $attributes,
            ['workspace_id', 'provider', 'entity_type', 'entity_id', 'date'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCrmCompany(array $attributes): NormalizedCrmCompany
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_company_id']);

        return $this->upsertModel(
            NormalizedCrmCompany::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_company_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCrmContact(ConnectorRawRecord $rawRecord, array $attributes): NormalizedCrmContact
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_contact_id']);

        $attributes['company_id'] = $attributes['company_id'] ?? $this->crmCompanyId(
            $rawRecord,
            (string) ($attributes['_provider_company_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedCrmContact::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_contact_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCrmDeal(ConnectorRawRecord $rawRecord, array $attributes): NormalizedCrmDeal
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_deal_id']);

        $attributes['company_id'] = $attributes['company_id'] ?? $this->crmCompanyId(
            $rawRecord,
            (string) ($attributes['_provider_company_id'] ?? ''),
        );
        $attributes['contact_id'] = $attributes['contact_id'] ?? $this->crmContactId(
            $rawRecord,
            (string) ($attributes['_provider_contact_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedCrmDeal::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_deal_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCrmActivity(ConnectorRawRecord $rawRecord, array $attributes): NormalizedCrmActivity
    {
        $this->requireKeys($attributes, ['workspace_id', 'provider', 'provider_activity_id']);

        $attributes['company_id'] = $attributes['company_id'] ?? $this->crmCompanyId(
            $rawRecord,
            (string) ($attributes['_provider_company_id'] ?? ''),
        );
        $attributes['contact_id'] = $attributes['contact_id'] ?? $this->crmContactId(
            $rawRecord,
            (string) ($attributes['_provider_contact_id'] ?? ''),
        );
        $attributes['deal_id'] = $attributes['deal_id'] ?? $this->crmDealId(
            $rawRecord,
            (string) ($attributes['_provider_deal_id'] ?? ''),
        );

        return $this->upsertModel(
            NormalizedCrmActivity::class,
            $attributes,
            ['workspace_id', 'provider', 'provider_activity_id'],
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $uniqueKeys
     * @return TModel
     */
    private function upsertModel(string $modelClass, array $attributes, array $uniqueKeys): Model
    {
        /** @var Model $model */
        $model = new $modelClass;
        $persistable = $this->serializePersistableValues($this->persistable($attributes));
        $unique = Arr::only($persistable, $uniqueKeys);
        $now = now();
        $keyName = $model->getKeyName();
        $createdAt = $model->getCreatedAtColumn();
        $updatedAt = $model->getUpdatedAtColumn();
        $existingId = $modelClass::query()->where($unique)->value($keyName);

        $values = array_merge($persistable, [
            $keyName => $existingId ?: (string) Str::uuid(),
            $createdAt => $now,
            $updatedAt => $now,
        ]);

        $updateColumns = array_values(array_diff(
            array_keys($values),
            array_merge($uniqueKeys, [$keyName, $createdAt]),
        ));

        DB::table($model->getTable())->upsert([$values], $uniqueKeys, $updateColumns);

        return $modelClass::query()->where($unique)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function serializePersistableValues(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $attributes[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return $attributes;
    }

    private function marketingAccountId(ConnectorRawRecord $rawRecord, string $providerAccountId): ?string
    {
        if ($providerAccountId === '') {
            return null;
        }

        return NormalizedMarketingAccount::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_account_id', $providerAccountId)
            ->value('id');
    }

    private function campaignId(ConnectorRawRecord $rawRecord, string $providerCampaignId): ?string
    {
        if ($providerCampaignId === '') {
            return null;
        }

        return NormalizedCampaign::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_campaign_id', $providerCampaignId)
            ->value('id');
    }

    private function adGroupId(ConnectorRawRecord $rawRecord, string $providerAdGroupId): ?string
    {
        if ($providerAdGroupId === '') {
            return null;
        }

        return NormalizedAdGroup::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_ad_group_id', $providerAdGroupId)
            ->value('id');
    }

    private function crmCompanyId(ConnectorRawRecord $rawRecord, string $providerCompanyId): ?string
    {
        if ($providerCompanyId === '') {
            return null;
        }

        return NormalizedCrmCompany::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_company_id', $providerCompanyId)
            ->value('id');
    }

    private function crmContactId(ConnectorRawRecord $rawRecord, string $providerContactId): ?string
    {
        if ($providerContactId === '') {
            return null;
        }

        return NormalizedCrmContact::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_contact_id', $providerContactId)
            ->value('id');
    }

    private function crmDealId(ConnectorRawRecord $rawRecord, string $providerDealId): ?string
    {
        if ($providerDealId === '') {
            return null;
        }

        return NormalizedCrmDeal::query()
            ->where('workspace_id', $rawRecord->workspace_id)
            ->where('provider', $rawRecord->provider_key)
            ->where('provider_deal_id', $providerDealId)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function persistable(array $attributes): array
    {
        return Arr::where($attributes, function (mixed $value, string $key): bool {
            unset($value);

            return ! str_starts_with($key, '_');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $keys
     */
    private function requireKeys(array $attributes, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $attributes) || $attributes[$key] === null || trim((string) $attributes[$key]) === '') {
                throw new InvalidArgumentException("Normalized record is missing required key [{$key}].");
            }
        }
    }
}
