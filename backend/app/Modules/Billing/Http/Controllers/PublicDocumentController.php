<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\InvoiceDocuments;
use App\Modules\Billing\Infrastructure\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Accès sans compte au PDF d'une facture par lien signé et expirant (partage WhatsApp, L4-02).
 * Une signature absente, falsifiée ou expirée répond 404, comme une ressource inexistante (D-054).
 */
final class PublicDocumentController
{
    public function invoice(Request $request, string $company, string $invoice, InvoiceDocuments $documents): Response
    {
        abort_unless($request->hasValidSignature(), 404);

        /** @var Response $response */
        $response = $documents->runAs($company, function () use ($invoice, $documents) {
            $model = Invoice::query()->findOrFail($invoice);

            return InvoiceController::pdfResponse($documents->invoicePdf($model), "facture-{$model->number}.pdf");
        });

        return $response;
    }
}
