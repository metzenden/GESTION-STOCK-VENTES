<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Http\Resources\StockDocumentPresenter;
use App\Modules\Inventory\Infrastructure\Models\Inventory;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Contracts\PdfRenderer;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Inventaires (docs/05 §2.4). */
final class InventoryController
{
    public function __construct(
        private readonly InventoryService $inventories,
        private readonly StockDocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate(['filter.status' => ['sometimes', 'in:draft,counting,review,validated,cancelled']]);
        $query = Inventory::query()->orderByDesc('created_at')->orderByDesc('id');

        if (isset($validated['filter']['status'])) {
            $query->where('status', $validated['filter']['status']);
        }

        return Paginated::response($query->paginate($paging['per_page'], ['*'], 'page', $paging['page']), fn (Inventory $i) => $this->presenter->inventory($i, false));
    }

    public function show(string $inventory): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->inventory(Inventory::query()->findOrFail($inventory))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'string', 'size:26'],
            'variant_ids' => ['sometimes', 'array', 'max:2000'],
            'variant_ids.*' => ['string', 'size:26'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $inventory = $this->inventories->create($validated['warehouse_id'], array_values($validated['variant_ids'] ?? []), $validated['note'] ?? null, $this->actor($request));

        return new JsonResponse(['data' => $this->presenter->inventory($inventory)], 201);
    }

    public function start(Request $request, string $inventory): JsonResponse
    {
        return $this->respond($this->inventories->start($this->find($inventory), $this->actor($request)));
    }

    public function closeCounting(Request $request, string $inventory): JsonResponse
    {
        return $this->respond($this->inventories->closeCounting($this->find($inventory), $this->actor($request)));
    }

    public function reopen(Request $request, string $inventory): JsonResponse
    {
        return $this->respond($this->inventories->reopen($this->find($inventory), $this->actor($request)));
    }

    public function cancel(Request $request, string $inventory): JsonResponse
    {
        return $this->respond($this->inventories->cancel($this->find($inventory), $this->actor($request)));
    }

    public function validate(Request $request, string $inventory): JsonResponse
    {
        $inventory = $this->find($inventory);
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->respond($this->inventories->validate($inventory, $validated['reason'] ?? null, $this->actor($request)));
    }

    public function count(Request $request, string $inventory, string $line): JsonResponse
    {
        $inventory = $this->find($inventory);
        $validated = $request->validate([
            'qty_counted' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->inventories->count($inventory, $line, (int) $validated['qty_counted'], $validated['note'] ?? null, (string) $this->actor($request));

        return $this->respond(Inventory::query()->findOrFail($inventory));
    }

    /** Feuille de comptage A4 (L2-12) : quantités théoriques volontairement absentes pour un comptage à l'aveugle. */
    public function sheet(string $inventory, ViewFactory $views, PdfRenderer $pdf, CompanyDirectory $companies): Response
    {
        $row = Inventory::query()->findOrFail($inventory);
        $data = $this->presenter->inventory($row);
        $html = $views->make('pdf.inventory-sheet', [
            'inventory' => $data,
            'warehouse' => Warehouse::query()->findOrFail($row->warehouse_id),
            'company' => $companies->find($row->company_id),
        ])->render();

        return new Response($pdf->renderHtml($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="inventaire-'.$row->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function respond(Inventory $inventory): JsonResponse
    {
        return new JsonResponse(['data' => $this->presenter->inventory($inventory->refresh())]);
    }

    private function find(string $id): string
    {
        return Inventory::query()->findOrFail($id)->id;
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
