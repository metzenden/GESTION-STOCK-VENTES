<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\FinancialIndicators;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Indicateurs financiers de gestion (L5-05) ; marge, résultat et trésorerie présentés séparément (CDC §14.5). */
final class IndicatorsController
{
    public function show(Request $request, FinancialIndicators $indicators): JsonResponse
    {
        $v = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($v['to']) ? CarbonImmutable::parse($v['to']) : CarbonImmutable::now();
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from']) : $to->startOfMonth();

        return new JsonResponse(['data' => $indicators->compute($from, $to)]);
    }
}
