<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CompanyContextSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /context/company : le super-admin choisit l'entreprise active (null pour en sortir). */
final class CompanyContextController
{
    public function __invoke(Request $request, CompanyContextSwitcher $switcher): JsonResponse
    {
        $validated = $request->validate(['company_id' => ['present', 'nullable', 'string', 'size:26']]);

        $company = $switcher->switch($request->session(), $validated['company_id']);

        return new JsonResponse(['data' => ['company' => $company?->toArray()]]);
    }
}
