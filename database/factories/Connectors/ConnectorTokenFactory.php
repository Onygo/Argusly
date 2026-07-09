<?php

namespace Database\Factories\Connectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorToken>
 */
class ConnectorTokenFactory extends Factory
{
    protected $model = ConnectorToken::class;

    public function definition(): array
    {
        $account = ConnectorAccount::query()->first() ?? ConnectorAccount::factory()->create();

        return [
            'connector_account_id' => $account->id,
            'access_token' => 'access-'.fake()->sha256(),
            'refresh_token' => 'refresh-'.fake()->sha256(),
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour(),
            'refreshed_at' => now(),
            'revoked_at' => null,
            'rotation_metadata_json' => [],
        ];
    }
}
