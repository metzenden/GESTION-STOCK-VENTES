<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\IdentityAccess\Contracts\Invitations;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use App\Modules\Tenancy\Domain\Enums\CompanyStatus;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * Création atomique d'une entreprise (ADM-001, L1-09) : entreprise, puis chaque étape enregistrée
 * par les modules (paramètres, séquences, rôles…), puis l'invitation de l'administrateur.
 * Un échec à n'importe quelle étape annule tout ; l'e-mail d'invitation n'est envoyé qu'après commit.
 */
final class CompanyProvisioner
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Container $container,
        private readonly TenantContext $tenant,
        private readonly Invitations $invitations,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $identity */
    public function create(array $identity, string $code, string $referenceCurrency, string $adminEmail, ?string $createdBy): Company
    {
        return $this->db->transaction(function () use ($identity, $code, $referenceCurrency, $adminEmail, $createdBy) {
            $company = new Company;
            $company->fill(array_intersect_key($identity, array_flip(Company::IDENTITY_FIELDS)));
            $company->forceFill([
                'code' => $code,
                'reference_currency' => $referenceCurrency,
                'status' => CompanyStatus::Provisioning,
                'created_by' => $createdBy,
            ])->save();

            $this->tenant->runAs($company->id, function () use ($company, $adminEmail) {
                foreach ($this->steps() as $step) {
                    $step->provision($company->id);
                }

                $this->invitations->inviteCompanyAdmin($company->id, $adminEmail);
            });

            $company->forceFill(['status' => CompanyStatus::Active])->save();

            $this->audit->record('company.provisioned', 'company', $company->id, [], [
                'code' => $code, 'admin_email' => $adminEmail,
            ], companyId: $company->id);

            // Valeurs par défaut posées par la base (pays, fuseau horaire).
            return $company->refresh();
        });
    }

    /** @return list<CompanyProvisioningStep> */
    private function steps(): array
    {
        /** @var list<CompanyProvisioningStep> $steps */
        $steps = iterator_to_array($this->container->tagged(CompanyProvisioningStep::TAG), false);
        usort($steps, fn (CompanyProvisioningStep $a, CompanyProvisioningStep $b) => $a->priority() <=> $b->priority());

        return $steps;
    }
}
