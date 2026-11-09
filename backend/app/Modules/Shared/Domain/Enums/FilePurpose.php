<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Enums;

/**
 * Usage d'un fichier stocké : détermine les formats et tailles autorisés (CDC §25.2).
 */
enum FilePurpose: string
{
    case ProductImage = 'product_image';
    case Logo = 'logo';
    case SupplierDocument = 'supplier_doc';
    case ShipmentDocument = 'shipment_doc';
    case PackingList = 'packing_list';
    case Import = 'import';
    case ExpenseReceipt = 'expense_receipt';
    case GeneratedPdf = 'generated_pdf';
    case Export = 'export';

    private const IMAGES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PDF = ['application/pdf' => 'pdf'];

    private const SPREADSHEETS = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv',
        'text/plain' => 'csv',
    ];

    /** @return array<string, string> type MIME détecté => extension de stockage */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::ProductImage, self::Logo => self::IMAGES,
            self::SupplierDocument, self::ShipmentDocument, self::ExpenseReceipt => self::PDF + self::IMAGES,
            self::PackingList => self::SPREADSHEETS + self::PDF,
            self::Import => self::SPREADSHEETS,
            self::GeneratedPdf => self::PDF,
            self::Export => self::SPREADSHEETS + self::PDF,
        };
    }

    public function maxBytes(): int
    {
        return match ($this) {
            self::Logo => 2 * 1024 * 1024,
            self::ProductImage => 5 * 1024 * 1024,
            self::SupplierDocument, self::ShipmentDocument, self::ExpenseReceipt => 10 * 1024 * 1024,
            self::PackingList, self::Import => 20 * 1024 * 1024,
            self::GeneratedPdf, self::Export => 50 * 1024 * 1024,
        };
    }
}
