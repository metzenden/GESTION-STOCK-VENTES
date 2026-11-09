<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use DateTimeInterface;

/** Enregistrement d'un règlement client (PAY-001 à PAY-005), utilisé par la vente comptoir. */
interface PaymentRecorder
{
    /** @return array{id: string, number: string} */
    public function record(string $invoiceId, string $method, string $accountId, string $amount, DateTimeInterface $paidAt, ?string $externalReference, ?string $actorId): array;
}
