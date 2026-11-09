<?php

declare(strict_types=1);

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Application\InitialImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Imports initiaux (docs/05 §2.9) : simulation puis import réel, tout ou rien. */
final class InitialImportController
{
    private const PERMISSIONS = ['products' => 'products.create', 'customers' => 'customers.manage', 'suppliers' => 'suppliers.manage'];

    public function store(Request $request, string $type, InitialImportService $imports): JsonResponse
    {
        abort_unless(isset(self::PERMISSIONS[$type]), 404);
        abort_unless(Gate::allows(self::PERMISSIONS[$type]), 403);
        $v = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'], 'simulate' => ['sometimes', 'boolean']]);
        $file = $request->file('file');
        $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $actor = $request->user()?->getAuthIdentifier();
        $report = $imports->run($type, (string) $file->getRealPath(), $format, (bool) ($v['simulate'] ?? true), is_string($actor) ? $actor : null);

        return new JsonResponse(['data' => $report], $report['errors'] === [] ? 200 : 422);
    }
}
