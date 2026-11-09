<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Infrastructure\Models\CreditNote;
use App\Modules\Billing\Infrastructure\Models\CreditNoteLine;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use App\Modules\Billing\Infrastructure\Models\InvoiceLine;
use App\Modules\Shared\Contracts\PdfRenderer;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Money\MoneyFormatter;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\URL;

/** PDF des factures et avoirs, lien signé de partage et message WhatsApp prérempli (L4-02, L4-15). */
final class InvoiceDocuments
{
    public const LINK_DAYS = 30;

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfRenderer $pdf,
        private readonly MoneyFormatter $money,
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
    ) {}

    public function invoicePdf(Invoice $invoice): string
    {
        $m = fn (string $a) => $this->money->format($a, $invoice->currency);
        $c = $invoice->customer_snapshot;
        $docs = (array) ($invoice->company_snapshot['documents'] ?? []);

        return $this->pdf->renderHtml($this->views->make('pdf.document', [
            'title' => 'Facture',
            'number' => $invoice->number,
            'company' => $invoice->company_snapshot,
            'meta' => ['Date' => $invoice->issue_date->format('d/m/Y'), 'Échéance' => $invoice->due_date->format('d/m/Y')],
            'party' => ['label' => 'Client', 'lines' => [$c['name'] ?? null, $c['address'] ?? null, $c['phone'] ?? null, $c['email'] ?? null]],
            'columns' => ['Désignation', 'Qté', 'Prix unitaire', 'Remise', 'Taxe', 'Total'],
            'rows' => $invoice->lines()->get()->map(fn (InvoiceLine $l) => [
                $l->description, "{$l->qty_units} {$l->unit_label}", $m($l->unit_price), $m($l->discount_amount), $m($l->tax_amount), $m($l->line_total),
            ])->all(),
            'totals' => array_filter([
                'Sous-total' => $m($invoice->subtotal),
                'Remises' => $m($invoice->discount_total),
                'Taxes' => $m($invoice->tax_total),
                'Total' => $m($invoice->total),
            ]) + ($invoice->amount_paid !== '0.0000' || $invoice->amount_credited !== '0.0000' ? ['Reste à payer' => $m($invoice->balance_due)] : []),
            'notice' => $invoice->status === 'cancelled' ? 'Facture annulée par avoir.' : null,
            'texts' => [$docs['payment_instructions'] ?? null, $docs['legal_mentions'] ?? null, $docs['footer'] ?? null],
        ])->render());
    }

    public function creditNotePdf(CreditNote $note): string
    {
        $m = fn (string $a) => $this->money->format($a, $note->currency);
        $company = $note->company_snapshot ?? $this->companies->identity($this->tenant->requireCompanyId());

        return $this->pdf->renderHtml($this->views->make('pdf.document', [
            'title' => 'Avoir',
            'number' => $note->number ?? 'Brouillon',
            'company' => $company,
            'meta' => array_filter(['Date' => $note->issued_at?->format('d/m/Y'), 'Motif' => $note->reason]),
            'party' => null,
            'columns' => ['Désignation', 'Quantité', 'Taxe', 'Montant'],
            'rows' => $note->lines()->get()->map(fn (CreditNoteLine $l) => [$l->description, (string) ($l->quantity ?? ''), $m($l->tax_amount), $m($l->line_total)])->all(),
            'totals' => ['Total de l’avoir' => $m($note->total)],
            'notice' => null,
            'texts' => [(string) (($company['documents'] ?? [])['footer'] ?? '')],
        ])->render());
    }

    /** Lien signé (30 jours) donnant accès au PDF sans compte, pour l'envoi WhatsApp. */
    public function shareLink(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('public.invoices.pdf', now()->addDays(self::LINK_DAYS), [
            'company' => $this->tenant->requireCompanyId(), 'invoice' => $invoice->id,
        ]);
    }

    /** @return array{message: string, url: string|null, share_link: string} */
    public function whatsapp(Invoice $invoice, bool $reminder): array
    {
        $link = $this->shareLink($invoice);
        $name = (string) ($invoice->customer_snapshot['name'] ?? '');
        $trade = (string) ($this->companies->identity($this->tenant->requireCompanyId())['trade_name'] ?? '');
        $total = $this->money->format($invoice->total, $invoice->currency);
        $balance = $this->money->format($invoice->balance_due, $invoice->currency);
        $message = $reminder
            ? "Bonjour {$name}, sauf erreur de notre part, la facture {$invoice->number} du {$invoice->issue_date->format('d/m/Y')} présente un solde de {$balance} (échéance le {$invoice->due_date->format('d/m/Y')}). Merci de votre règlement. {$trade}\n{$link}"
            : "Bonjour {$name}, voici votre facture {$invoice->number} d'un montant de {$total}. {$trade}\n{$link}";
        $phone = preg_replace('/\D/', '', (string) ($invoice->customer_snapshot['phone'] ?? '')) ?? '';

        return ['message' => $message, 'url' => $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null, 'share_link' => $link];
    }

    /** @param  callable(): mixed  $callback */
    public function runAs(string $companyId, callable $callback): mixed
    {
        return $this->tenant->runAs($companyId, $callback);
    }
}
