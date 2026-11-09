<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Costing\Application\CostingDefaults;
use App\Modules\Costing\Application\CostModelService;
use App\Modules\Costing\Application\LandedCostService;
use App\Modules\Costing\Application\ShipmentCostService;
use App\Modules\Costing\Http\Resources\CostingPresenter;
use App\Modules\Costing\Infrastructure\Models\LandedCostCalculation;
use App\Modules\Costing\Infrastructure\Models\LandedCostCategory;
use App\Modules\Costing\Infrastructure\Models\LandedCostModel;
use App\Modules\Costing\Infrastructure\Models\ShipmentCost;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Frais d'approche, modèles de formules et calculs de coût de revient (docs/05 §2.5) — informations sensibles. */
final class CostingController
{
    public function __construct(
        private readonly CostingPresenter $presenter,
        private readonly ShipmentBook $shipments,
        private readonly TenantContext $tenant,
    ) {}

    public function categories(CostingDefaults $defaults): JsonResponse
    {
        $defaults->ensure();

        return new JsonResponse(['data' => LandedCostCategory::query()->orderByDesc('is_system')->orderBy('label')->get()
            ->map(fn (LandedCostCategory $c) => $this->presenter->category($c))->values()->all()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_]+$/', Rule::unique('landed_cost_categories', 'code')->where('company_id', $this->tenant->requireCompanyId())],
            'label' => ['required', 'string', 'max:64'],
            'default_allocation' => ['sometimes', 'in:cbm,direct'],
        ]);
        $category = new LandedCostCategory;
        $category->fill($validated + ['is_system' => false])->save();

        return new JsonResponse(['data' => $this->presenter->category($category->refresh())], 201);
    }

    public function costs(string $shipment): JsonResponse
    {
        $id = $this->shipment($shipment);

        return new JsonResponse(['data' => ShipmentCost::query()->where('shipment_id', $id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (ShipmentCost $c) => $this->presenter->cost($c))->values()->all()]);
    }

    public function storeCost(Request $request, string $shipment, ShipmentCostService $costs): JsonResponse
    {
        $id = $this->shipment($shipment);
        $validated = $request->validate(self::costRules(true));

        return new JsonResponse(['data' => $this->presenter->cost($costs->create($id, $validated, self::actor($request)))], 201);
    }

    public function updateCost(Request $request, string $shipment, string $cost, ShipmentCostService $costs): JsonResponse
    {
        $cost = $this->cost($shipment, $cost);
        $validated = $request->validate(self::costRules(false) + ['version' => ['sometimes', 'integer', 'min:1']]);
        $version = isset($validated['version']) ? (int) $validated['version'] : null;
        unset($validated['version']);

        return new JsonResponse(['data' => $this->presenter->cost($costs->update($cost, $validated, $version, self::actor($request)))]);
    }

    public function confirmCost(Request $request, string $shipment, string $cost, ShipmentCostService $costs): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->cost($costs->confirm($this->cost($shipment, $cost), self::actor($request)))]);
    }

    public function cancelCost(Request $request, string $shipment, string $cost, ShipmentCostService $costs): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->cost($costs->cancel($this->cost($shipment, $cost), self::actor($request)))]);
    }

    public function models(CostingDefaults $defaults): JsonResponse
    {
        $defaults->ensure();

        return new JsonResponse(['data' => LandedCostModel::query()->orderBy('code')->orderByDesc('version')->get()
            ->map(fn (LandedCostModel $m) => $this->presenter->model($m))->values()->all()]);
    }

    public function storeModel(Request $request, CostModelService $models): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'expression' => ['required', 'string', 'max:2000'],
        ]);

        return new JsonResponse(['data' => $this->presenter->model($models->create($validated['code'], $validated['name'], $validated['expression'], self::actor($request)))], 201);
    }

    public function storeModelVersion(Request $request, string $model, CostModelService $models): JsonResponse
    {
        $id = LandedCostModel::query()->findOrFail($model)->id;
        $validated = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'expression' => ['required', 'string', 'max:2000']]);

        return new JsonResponse(['data' => $this->presenter->model($models->newVersion($id, $validated['name'] ?? null, $validated['expression'], self::actor($request)))], 201);
    }

    public function testModel(Request $request, string $model, CostModelService $models): JsonResponse
    {
        $id = LandedCostModel::query()->findOrFail($model)->id;
        $validated = $request->validate(['sample' => ['sometimes', 'array'], 'sample.*' => ['string', 'max:40']]);
        /** @var array<string, string> $sample */
        $sample = $validated['sample'] ?? [];

        return new JsonResponse(['data' => $models->test($id, $sample, self::actor($request))]);
    }

    public function activateModel(Request $request, string $model, CostModelService $models): JsonResponse
    {
        $id = LandedCostModel::query()->findOrFail($model)->id;

        return new JsonResponse(['data' => $this->presenter->model($models->activate($id, self::actor($request)))]);
    }

    public function calculations(string $shipment): JsonResponse
    {
        $id = $this->shipment($shipment);

        return new JsonResponse(['data' => LandedCostCalculation::query()->where('shipment_id', $id)->orderByDesc('revision')->get()
            ->map(fn (LandedCostCalculation $c) => $this->presenter->calculation($c, false))->values()->all()]);
    }

    public function compute(Request $request, string $shipment, LandedCostService $service): JsonResponse
    {
        $id = $this->shipment($shipment);
        $validated = $request->validate([
            'model_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'rates' => ['sometimes', 'array'],
            'rates.*' => ['string', 'regex:/^\d{1,10}(\.\d{1,10})?$/'],
        ]);
        /** @var array<string, string> $rates */
        $rates = $validated['rates'] ?? [];

        return new JsonResponse(['data' => $this->presenter->calculation($service->compute($id, $validated['model_id'] ?? null, $rates, self::actor($request)))], 201);
    }

    public function showCalculation(string $calculation): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->calculation(LandedCostCalculation::query()->findOrFail($calculation))]);
    }

    public function finalize(Request $request, string $calculation, LandedCostService $service): JsonResponse
    {
        $id = LandedCostCalculation::query()->findOrFail($calculation)->id;

        return new JsonResponse(['data' => $this->presenter->calculation($service->finalize($id, self::actor($request)))]);
    }

    /** @return array<string, list<mixed>> */
    private static function costRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'category_id' => [$required, 'string', 'size:26'],
            'supplier_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => [$required, 'string', 'size:3', Rule::exists('currencies', 'code')],
            'amount' => [$required, 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'exchange_rate' => ['sometimes', 'string', 'regex:/^\d{1,10}(\.\d{1,10})?$/'],
            'allocation_method' => ['sometimes', 'in:cbm,direct'],
            'target_shipment_line_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'is_estimate' => ['sometimes', 'boolean'],
        ];
    }

    private function shipment(string $id): string
    {
        $shipment = $this->shipments->find($id);
        abort_if($shipment === null, 404);

        return $shipment->id;
    }

    private function cost(string $shipment, string $cost): string
    {
        return ShipmentCost::query()->where('shipment_id', $this->shipment($shipment))->findOrFail($cost)->id;
    }

    private static function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
