<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum MovementType: string
{
    case Receipt = 'receipt';
    case SaleDelivery = 'sale_delivery';
    case ReturnIn = 'return_in';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Loss = 'loss';
    case Opening = 'opening';
    case Reversal = 'reversal';

    public function isInbound(): bool
    {
        return in_array($this, [self::Receipt, self::ReturnIn, self::TransferIn, self::AdjustmentIn, self::Opening], true);
    }
}
