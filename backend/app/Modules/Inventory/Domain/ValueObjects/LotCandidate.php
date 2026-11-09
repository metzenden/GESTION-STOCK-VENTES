<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

/** Lot éligible à une sortie : quantité libre = physique − bloqué − en livraison. */
final readonly class LotCandidate
{
    public function __construct(
        public string $lotId,
        public string $lotNumber,
        /** Horodatage triable (ISO 8601 UTC) de la réception du lot. */
        public string $receivedAt,
        public int $free,
    ) {}
}
