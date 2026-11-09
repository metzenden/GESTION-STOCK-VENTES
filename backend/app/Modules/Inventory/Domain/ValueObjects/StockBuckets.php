<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use LogicException;

/**
 * Compartiments d'un couple (dépôt, variante) — docs/04 §4.1 :
 * physique = disponible + réservé + bloqué + en livraison, chaque terme ≥ 0 (ST-007).
 *
 * Toutes les opérations renvoient un nouvel état ou échouent sans rien modifier.
 */
final readonly class StockBuckets
{
    public function __construct(
        public int $onHand = 0,
        public int $reserved = 0,
        public int $blocked = 0,
        public int $inDelivery = 0,
    ) {
        if ($onHand < 0 || $reserved < 0 || $blocked < 0 || $inDelivery < 0 || $this->available() < 0) {
            throw new LogicException('Compartiments de stock incohérents.');
        }
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved - $this->blocked - $this->inDelivery;
    }

    /** Entrée physique (réception, retour, ajustement positif, transfert entrant). */
    public function receive(int $qty): self
    {
        self::assertPositive($qty);

        return new self($this->onHand + $qty, $this->reserved, $this->blocked, $this->inDelivery);
    }

    /** Sortie prélevée sur le disponible (ajustement négatif, perte). */
    public function issue(int $qty): self
    {
        $this->assertAvailable($qty);

        return new self($this->onHand - $qty, $this->reserved, $this->blocked, $this->inDelivery);
    }

    /** ST-003, ST-006 : réservation complète ou refus. */
    public function reserve(int $qty): self
    {
        $this->assertAvailable($qty);

        return new self($this->onHand, $this->reserved + $qty, $this->blocked, $this->inDelivery);
    }

    public function release(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->reserved, 'réservé');

        return new self($this->onHand, $this->reserved - $qty, $this->blocked, $this->inDelivery);
    }

    /** ST-004 : expédition — le réservé passe « en livraison ». */
    public function ship(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->reserved, 'réservé');

        return new self($this->onHand, $this->reserved - $qty, $this->blocked, $this->inDelivery + $qty);
    }

    /** ST-005 : remise au client — sortie définitive et solde de « en livraison ». */
    public function deliver(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->inDelivery, 'en livraison');

        return new self($this->onHand - $qty, $this->reserved, $this->blocked, $this->inDelivery - $qty);
    }

    /** Livraison échouée : la marchandise revient et reste réservée pour la commande. */
    public function failDelivery(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->inDelivery, 'en livraison');

        return new self($this->onHand, $this->reserved + $qty, $this->blocked, $this->inDelivery - $qty);
    }

    /** Sortie d'une quantité réservée (transfert expédié). */
    public function issueReserved(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->reserved, 'réservé');

        return new self($this->onHand - $qty, $this->reserved - $qty, $this->blocked, $this->inDelivery);
    }

    public function hold(int $qty): self
    {
        $this->assertAvailable($qty);

        return new self($this->onHand, $this->reserved, $this->blocked + $qty, $this->inDelivery);
    }

    public function releaseHold(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->blocked, 'bloqué');

        return new self($this->onHand, $this->reserved, $this->blocked - $qty, $this->inDelivery);
    }

    /** Sortie d'une quantité bloquée (perte validée). */
    public function issueHeld(int $qty): self
    {
        self::assertPositive($qty);
        self::assertWithin($qty, $this->blocked, 'bloqué');

        return new self($this->onHand - $qty, $this->reserved, $this->blocked - $qty, $this->inDelivery);
    }

    private function assertAvailable(int $qty): void
    {
        self::assertPositive($qty);

        if ($qty > $this->available()) {
            throw BusinessRuleViolation::insufficientStock($this->available(), $qty);
        }
    }

    private static function assertPositive(int $qty): void
    {
        if ($qty <= 0) {
            throw new LogicException('Quantité de stock strictement positive attendue.');
        }
    }

    private static function assertWithin(int $qty, int $bucket, string $label): void
    {
        if ($qty > $bucket) {
            throw new LogicException("Quantité {$qty} supérieure au compartiment « {$label} » ({$bucket}).");
        }
    }
}
