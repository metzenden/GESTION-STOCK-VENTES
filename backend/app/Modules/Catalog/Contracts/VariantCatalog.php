<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** Accès des autres modules aux variantes de l'entreprise active. */
interface VariantCatalog
{
    /**
     * Variantes connues de l'entreprise active, indexées par identifiant (les inconnues sont absentes).
     *
     * @param  list<string>  $variantIds
     * @return array<string, VariantSummary>
     */
    public function describe(array $variantIds): array;

    /**
     * Positionne has_history sur les produits concernés : leur suppression devient impossible (CAT-006).
     *
     * @param  list<string>  $variantIds
     */
    public function markHistory(array $variantIds): void;

    /** Fige le facteur d'un conditionnement utilisé dans un document (CAT-007). */
    public function lockUnit(string $unitId): void;

    /**
     * Conditionnements connus de l'entreprise active, indexés par identifiant.
     *
     * @param  list<string>  $unitIds
     * @return array<string, UnitSummary>
     */
    public function units(array $unitIds): array;

    /** Conditionnement de base (facteur 1) d'une variante. */
    public function baseUnit(string $variantId): ?UnitSummary;

    /** Conditionnement portant ce code-barres dans l'entreprise active. */
    public function unitByBarcode(string $barcode): ?UnitSummary;

    /**
     * Variante correspondant à une référence fournisseur : SKU, référence produit (variante par défaut) ou code-barres.
     */
    public function matchReference(string $reference): ?string;

    /**
     * Crée explicitement un produit stocké simple (variante par défaut) depuis un import (IMP-006) ; renvoie sa variante.
     */
    public function createStockProduct(string $reference, string $name, ?int $piecesPerCarton, ?string $actorId): string;

    /**
     * Grille tarifaire de l'entreprise active (null = grille par défaut).
     *
     * @return array{id: string, code: string, name: string, currency: string}|null
     */
    public function priceList(?string $priceListId): ?array;

    /**
     * Prix proposé d'un conditionnement à une date (prix direct, sinon prix pièce × facteur).
     *
     * @return array{price: string, source: string}|null
     */
    public function quote(string $variantId, string $unitId, string $priceListId, \DateTimeInterface $date): ?array;
}
