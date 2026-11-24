<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\ElectoralCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic campaigns for tests: owner group, status and authors are set with forceFill like the server does.
 *
 * @extends Factory<ElectoralCampaign>
 */
class ElectoralCampaignFactory extends Factory
{
    protected $model = ElectoralCampaign::class;

    public function definition(): array
    {
        return [
            'name' => 'Campaña '.fake()->unique()->numerify('####'),
            'cargo' => 'gobernacion',
            'election_year' => 2027,
            'scope_level' => ElectoralCampaign::SCOPE_DEPARTMENT,
            'factor_votos' => 1.10,
            'factor_registro' => 1.00,
            'votos_por_lider' => 50,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ElectoralCampaign $campaign) {
            $campaign->forceFill([
                'campaign_id' => $campaign->campaign_id ?? Campaign::DEFAULT_ID,
                'status' => $campaign->status ?? ElectoralCampaign::ACTIVE,
            ]);
        });
    }

    public function forGroup(string $campaignId): static
    {
        return $this->afterMaking(fn (ElectoralCampaign $c) => $c->forceFill(['campaign_id' => $campaignId]));
    }

    public function archived(): static
    {
        return $this->afterMaking(fn (ElectoralCampaign $c) => $c->forceFill(['status' => ElectoralCampaign::ARCHIVED, 'archived_at' => now()]));
    }

    public function cargo(string $cargo, string $scope): static
    {
        return $this->state(['cargo' => $cargo, 'scope_level' => $scope]);
    }
}
