<?php

namespace App\Services\DataConnectors\Normalization\Crm;

use App\Contracts\Connectors\Normalization\NormalizedRecordMapper;
use App\Data\Connectors\NormalizedRecord;
use App\Models\Connectors\ConnectorRawRecord;
use App\Services\DataConnectors\Normalization\AbstractNormalizedRecordMapper;

abstract class AbstractCrmNormalizedRecordMapper extends AbstractNormalizedRecordMapper implements NormalizedRecordMapper
{
    /**
     * @return array<int, NormalizedRecord>
     */
    public function map(ConnectorRawRecord $rawRecord): array
    {
        $payload = $this->payload($rawRecord);
        $type = $this->objectType($rawRecord);

        return match ($type) {
            'companies', 'accounts', 'organizations' => array_filter([$this->company($rawRecord, $payload)]),
            'contacts', 'persons', 'people', 'leads' => array_filter([$this->contact($rawRecord, $payload)]),
            'deals', 'opportunities' => array_filter([$this->deal($rawRecord, $payload)]),
            'activities', 'tasks', 'events', 'calls', 'meetings' => array_filter([$this->activity($rawRecord, $payload)]),
            default => [],
        };
    }

    protected function objectType(ConnectorRawRecord $rawRecord): string
    {
        $type = strtolower(trim((string) (
            data_get($rawRecord->dataset?->config_json ?? [], 'object')
            ?: data_get($rawRecord->dataset?->config_json ?? [], 'provider_object')
            ?: $rawRecord->record_type
            ?: $rawRecord->dataset_key
        )));

        return match ($type) {
            'account', 'accounts', 'company', 'companies', 'organization', 'organizations' => in_array($type, ['account', 'accounts'], true) ? 'accounts' : 'companies',
            'contact', 'contacts', 'person', 'persons', 'people', 'lead', 'leads' => in_array($type, ['person', 'persons', 'people'], true) ? 'persons' : 'contacts',
            'deal', 'deals', 'opportunity', 'opportunities' => in_array($type, ['opportunity', 'opportunities'], true) ? 'opportunities' : 'deals',
            'activity', 'activities', 'task', 'tasks', 'event', 'events', 'call', 'calls', 'meeting', 'meetings' => in_array($type, ['task', 'tasks'], true) ? 'tasks' : 'activities',
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function company(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $companyId = $this->recordId($payload);

        if ($companyId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::CRM_COMPANY, [
            'provider_company_id' => $companyId,
            'name' => $this->property($payload, ['name', 'Name', 'company', 'company_name']),
            'domain' => $this->property($payload, ['domain', 'website', 'Website', 'web_url']),
            'industry' => $this->property($payload, ['industry', 'Industry']),
            'size' => $this->property($payload, ['numberofemployees', 'number_of_employees', 'employees', 'size']),
            'owner_id' => $this->property($payload, ['hubspot_owner_id', 'owner_id', 'OwnerId', 'user_id']),
            'lifecycle_stage' => $this->property($payload, ['lifecyclestage', 'lifecycle_stage', 'LifecycleStage']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function contact(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $contactId = $this->recordId($payload);

        if ($contactId === null) {
            return null;
        }

        $email = $this->property($payload, ['email', 'Email', 'primary_email']);

        return NormalizedRecord::make(NormalizedRecord::CRM_CONTACT, [
            'provider_contact_id' => $contactId,
            '_provider_company_id' => $this->companyId($payload),
            'email_hash' => $this->emailHash((string) $rawRecord->workspace_id, $email),
            'first_name' => $this->property($payload, ['firstname', 'first_name', 'FirstName', 'first_name']),
            'last_name' => $this->property($payload, ['lastname', 'last_name', 'LastName']),
            'job_title' => $this->property($payload, ['jobtitle', 'job_title', 'Title', 'title']),
            'owner_id' => $this->property($payload, ['hubspot_owner_id', 'owner_id', 'OwnerId', 'user_id']),
            'lifecycle_stage' => $this->property($payload, ['lifecyclestage', 'lifecycle_stage', 'LifecycleStage']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function deal(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $dealId = $this->recordId($payload);

        if ($dealId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::CRM_DEAL, [
            'provider_deal_id' => $dealId,
            '_provider_company_id' => $this->companyId($payload),
            '_provider_contact_id' => $this->contactId($payload),
            'pipeline' => $this->property($payload, ['pipeline', 'PipelineId', 'pipeline_id']),
            'stage' => $this->property($payload, ['dealstage', 'stage', 'StageName', 'stage_id', 'status']),
            'amount' => $this->propertyDecimal($payload, ['amount', 'Amount', 'value']),
            'currency' => $this->property($payload, ['currency', 'CurrencyIsoCode', 'currency_code']),
            'probability' => $this->propertyDecimal($payload, ['probability', 'Probability']),
            'close_date' => $this->propertyDate($payload, ['closedate', 'close_date', 'CloseDate', 'expected_close_date']),
            'owner_id' => $this->property($payload, ['hubspot_owner_id', 'owner_id', 'OwnerId', 'user_id']),
            'status' => $this->property($payload, ['status', 'deal_status', 'IsClosed']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function activity(ConnectorRawRecord $rawRecord, array $payload): ?NormalizedRecord
    {
        $activityId = $this->recordId($payload);

        if ($activityId === null) {
            return null;
        }

        return NormalizedRecord::make(NormalizedRecord::CRM_ACTIVITY, [
            'provider_activity_id' => $activityId,
            '_provider_company_id' => $this->companyId($payload),
            '_provider_contact_id' => $this->contactId($payload),
            '_provider_deal_id' => $this->dealId($payload),
            'type' => $this->property($payload, ['type', 'activity_type', 'TaskSubtype', 'engagement.type']),
            'subject' => $this->property($payload, ['subject', 'Subject', 'title', 'note']),
            'occurred_at' => $this->propertyDateTime($payload, ['occurred_at', 'activity_date', 'ActivityDate', 'created_at', 'CreatedDate', 'due_date']),
            'owner_id' => $this->property($payload, ['hubspot_owner_id', 'owner_id', 'OwnerId', 'user_id']),
        ], $this->rawReference($rawRecord));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function recordId(array $payload): ?string
    {
        return $this->string($payload, [
            'id',
            'Id',
            'ID',
            'properties.hs_object_id',
            'hs_object_id',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function companyId(array $payload): ?string
    {
        return $this->property($payload, [
            'associatedcompanyid',
            'associated_company_id',
            'company_id',
            'CompanyId',
            'AccountId',
            'account_id',
            'org_id',
            'organization_id',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function contactId(array $payload): ?string
    {
        return $this->property($payload, [
            'contact_id',
            'ContactId',
            'person_id',
            'primary_contact_id',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function dealId(array $payload): ?string
    {
        return $this->property($payload, [
            'deal_id',
            'DealId',
            'opportunity_id',
            'OpportunityId',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $names
     */
    protected function property(array $payload, array $names): ?string
    {
        $paths = [];

        foreach ($names as $name) {
            $paths[] = $name;
            $paths[] = 'properties.'.$name;
            $paths[] = 'data.'.$name;
        }

        return $this->string($payload, $paths);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $names
     */
    protected function propertyDecimal(array $payload, array $names): ?float
    {
        $paths = [];

        foreach ($names as $name) {
            $paths[] = $name;
            $paths[] = 'properties.'.$name;
            $paths[] = 'data.'.$name;
        }

        return $this->decimal($payload, $paths);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $names
     */
    protected function propertyDate(array $payload, array $names): ?string
    {
        $paths = [];

        foreach ($names as $name) {
            $paths[] = $name;
            $paths[] = 'properties.'.$name;
            $paths[] = 'data.'.$name;
        }

        return $this->date($payload, $paths);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $names
     */
    protected function propertyDateTime(array $payload, array $names): ?string
    {
        $paths = [];

        foreach ($names as $name) {
            $paths[] = $name;
            $paths[] = 'properties.'.$name;
            $paths[] = 'data.'.$name;
        }

        return $this->dateTime($payload, $paths);
    }
}
