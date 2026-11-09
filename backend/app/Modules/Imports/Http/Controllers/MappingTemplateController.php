<?php

declare(strict_types=1);

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Application\EloquentMappingTemplates;
use App\Modules\Imports\Contracts\MappingTemplates;
use App\Modules\Imports\Infrastructure\Models\ImportMappingTemplate;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Modèles de correspondance de colonnes (docs/05 §2.5), réutilisés d'un import à l'autre. */
final class MappingTemplateController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['filter.import_type' => ['sometimes', 'in:packing_list,products,opening_stock,customers,suppliers']]);
        $query = ImportMappingTemplate::query()->orderBy('import_type')->orderBy('name');

        if (isset($validated['filter']['import_type'])) {
            $query->where('import_type', $validated['filter']['import_type']);
        }

        return new JsonResponse(['data' => $query->get()->map(fn (ImportMappingTemplate $t) => EloquentMappingTemplates::toArray($t))->values()->all()]);
    }

    public function store(Request $request, MappingTemplates $templates, SupplierDirectory $suppliers): JsonResponse
    {
        $validated = $request->validate([
            'import_type' => ['required', 'in:packing_list,products,opening_stock,customers,suppliers'],
            'supplier_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'file_format' => ['required', 'in:xlsx,csv'],
            'mapping' => ['required', 'array'],
            'mapping.header_row' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'mapping.columns' => ['sometimes', 'array'],
            'mapping.columns.*' => ['string', 'max:120'],
        ]);
        if (isset($validated['supplier_id']) && $suppliers->find((string) $validated['supplier_id']) === null) {
            throw BusinessRuleViolation::invalidField('supplier_id', 'Ce fournisseur n\'existe pas.');
        }

        $actor = $request->user()?->getAuthIdentifier();
        $id = $templates->remember($validated['import_type'], $validated['supplier_id'] ?? null, $validated['name'], $validated['file_format'], $validated['mapping'], is_string($actor) ? $actor : null);

        return new JsonResponse(['data' => $templates->find($id)], 201);
    }
}
