<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Expressions;

use DomainException;

/** Formule invalide ou impossible à évaluer ; la position est en caractères depuis le début (0). */
final class ExpressionError extends DomainException
{
    public function __construct(string $message, public readonly ?int $position = null)
    {
        parent::__construct($message);
    }
}
