<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

/** Facturation vue par les ventes et les paiements. */
interface Invoicing
{
    /** Émission atomique d'une facture numérotée (BIL-001) ; renvoie son identifiant. */
    public function issue(InvoiceDraft $draft, ?string $actorId): string;

    public function find(string $invoiceId): ?InvoiceSummary;

    /** Verrouille la facture pour un règlement (FOR UPDATE). */
    public function lock(string $invoiceId): InvoiceSummary;

    /**
     * Enregistre un règlement (montant positif) ou sa contre-passation (négatif) : PAY-001, PAY-003, BIL-007.
     * $method est le moyen du règlement ; $clearMethod remet le moyen à NULL quand plus aucun règlement n'est actif (PAY-006).
     */
    public function recordPayment(string $invoiceId, string $method, string $signedAmount, bool $clearMethod = false): InvoiceSummary;

    /**
     * Crée et émet un avoir lié à une facture, imputé d'abord sur cette facture (CRN-002, D-019).
     * Soumis à l'ApprovalGate : renvoie le statut (issued ou pending_approval).
     *
     * @param  list<DocumentLine>  $lines
     * @return array{id: string, number: string|null, status: string, total: string}
     */
    public function creditInvoice(string $invoiceId, string $kind, string $reason, array $lines, ?string $returnId, ?string $actorId): array;
}
