<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('CO-####??')),
            'trade_name' => $this->faker->company(),
            'reference_currency' => 'XOF',
            'timezone' => 'Africa/Dakar',
            'status' => 'active',
        ];
    }

    public function currency(string $code): static
    {
        return $this->state(['reference_currency' => $code]);
    }

    /** Exécute les étapes de création (paramètres, séquences, rôles) sans envoyer d'invitation. */
    public function provisioned(): static
    {
        return $this->afterCreating(function (Company $company) {
            $steps = iterator_to_array(app()->tagged(CompanyProvisioningStep::TAG), false);
            usort($steps, fn (CompanyProvisioningStep $a, CompanyProvisioningStep $b) => $a->priority() <=> $b->priority());

            app(TenantContext::class)->runAs($company->id, function () use ($steps, $company) {
                foreach ($steps as $step) {
                    $step->provision($company->id);
                }
            });
        });
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended', 'suspended_at' => now()]);
    }
}
