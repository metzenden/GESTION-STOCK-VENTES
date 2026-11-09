<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface PdfRenderer
{
    /** Convertit un document HTML autonome en PDF A4 et renvoie le contenu binaire. */
    public function renderHtml(string $html): string;
}
