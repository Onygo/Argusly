<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function updateLegalFields(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): JsonResponse {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:200'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'vat_id' => ['nullable', 'string', 'max:64'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:255'],
            'billing_address.line2' => ['nullable', 'string', 'max:255'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:64'],
            'billing_address.city' => ['nullable', 'string', 'max:128'],
            'billing_address.country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $before = [
            'legal_name' => (string) ($organization->legal_name ?? ''),
            'billing_email' => (string) ($organization->billing_email ?? ''),
            'vat_id' => (string) ($organization->vat_id ?? ''),
            'billing_address' => $organization->billing_address,
        ];

        $organization->legal_name = $data['legal_name'] ?? null;
        $organization->billing_email = $data['billing_email'] ?? null;
        $organization->vat_id = $data['vat_id'] ?? null;
        if (array_key_exists('billing_address', $data)) {
            $organization->billing_address = [
                'line1' => data_get($data, 'billing_address.line1'),
                'line2' => data_get($data, 'billing_address.line2'),
                'postal_code' => data_get($data, 'billing_address.postal_code'),
                'city' => data_get($data, 'billing_address.city'),
                'country_code' => data_get($data, 'billing_address.country_code')
                    ? strtoupper((string) data_get($data, 'billing_address.country_code'))
                    : null,
            ];
        }
        $organization->save();

        $after = [
            'legal_name' => (string) ($organization->legal_name ?? ''),
            'billing_email' => (string) ($organization->billing_email ?? ''),
            'vat_id' => (string) ($organization->vat_id ?? ''),
            'billing_address' => $organization->billing_address,
        ];

        $auditLogs->log(
            actor: null,
            subject: $organization,
            action: 'company.legal_name.updated',
            before: $before,
            after: $after,
            request: $request
        );

        return response()->json([
            'id' => (int) $organization->id,
            'legal_name' => $organization->legal_name,
            'billing_email' => $organization->billing_email,
            'vat_id' => $organization->vat_id,
            'billing_address' => $organization->billing_address,
        ]);
    }
}

