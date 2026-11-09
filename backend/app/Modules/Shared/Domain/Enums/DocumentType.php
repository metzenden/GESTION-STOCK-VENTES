<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Enums;

/**
 * Types de documents numérotés. La séquence est indépendante par entreprise (MT-006)
 * et, pour les séquences annuelles, par année (FAC-2026-0001).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case Quotation = 'quotation';
    case SalesOrder = 'sales_order';
    case Delivery = 'delivery';
    case Return = 'return';
    case PurchaseOrder = 'purchase_order';
    case SupplierBill = 'supplier_bill';
    case SupplierPayment = 'supplier_payment';
    case Shipment = 'shipment';
    case GoodsReceipt = 'goods_receipt';
    case StockLot = 'stock_lot';
    case StockAdjustment = 'adjustment';
    case Inventory = 'inventory';
    case StockTransfer = 'transfer';
    case Expense = 'expense';
    case AccountTransfer = 'account_transfer';
    case ApprovalRequest = 'approval_request';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'FAC',
            self::CreditNote => 'AV',
            self::Receipt => 'REC',
            self::Quotation => 'DEV',
            self::SalesOrder => 'CMD',
            self::Delivery => 'BL',
            self::Return => 'RET',
            self::PurchaseOrder => 'BC',
            self::SupplierBill => 'ENG',
            self::SupplierPayment => 'PF',
            self::Shipment => 'ARR',
            self::GoodsReceipt => 'BR',
            self::StockLot => 'LOT',
            self::StockAdjustment => 'AJU',
            self::Inventory => 'INV',
            self::StockTransfer => 'TRF',
            self::Expense => 'DEP',
            self::AccountTransfer => 'VIR',
            self::ApprovalRequest => 'APP',
        };
    }

    public function defaultPadding(): int
    {
        return $this === self::StockLot ? 6 : 4;
    }

    public function isYearly(): bool
    {
        return true;
    }
}
