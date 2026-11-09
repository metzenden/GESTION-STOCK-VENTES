<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

/** Paramètres de l'entreprise active utiles aux autres modules. */
interface CompanyPreferences
{
    public function defaultSalesWarehouseId(): ?string;

    public function setDefaultSalesWarehouse(?string $warehouseId): void;

    /** Seuil de stock faible par défaut (variante sans seuil propre). */
    public function lowStockDefaultThreshold(): int;

    /** Délai de paiement par défaut des factures, en jours (BIL-004). */
    public function defaultPaymentTermsDays(): int;

    /**
     * Textes saisis par l'entreprise pour ses documents (mentions légales, pied de page, instructions de paiement).
     * Aucun texte réglementaire n'est fourni par défaut (VR-02).
     *
     * @return array{legal_mentions: string|null, footer: string|null, payment_instructions: string|null}
     */
    public function documentTexts(): array;

    /** APR-003 : l'auto-approbation est-elle permise (option forbid_self_approval, D-061) ? */
    public function allowsSelfApproval(): bool;
}
