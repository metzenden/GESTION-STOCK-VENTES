<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Utilisateurs de test. Mot de passe commun : UserFactory::PASSWORD.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'MotDePasse-Test-2026';

    protected $model = User::class;

    private static ?string $hash = null;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'is_super_admin' => false,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$hash ??= Hash::make(self::PASSWORD),
            'status' => UserStatus::Active,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->id]);
    }

    public function superAdmin(): static
    {
        return $this->state(['company_id' => null, 'is_super_admin' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => UserStatus::Disabled]);
    }

    /** Les colonnes protégées (company_id, is_super_admin) sont renseignées hors affectation de masse. */
    public function newModel(array $attributes = [])
    {
        $model = new User;
        $model->forceFill($attributes);

        return $model;
    }
}
