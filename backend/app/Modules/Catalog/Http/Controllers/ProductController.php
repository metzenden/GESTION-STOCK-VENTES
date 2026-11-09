<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\ProductImageService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Http\Resources\CatalogPresenter;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantUnit;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use App\Modules\Shared\Infrastructure\Http\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Produits (docs/05 §2.3). */
final class ProductController
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $paging = Paginated::input($request);
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'filter.status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'filter.category_id' => ['sometimes', 'string', 'size:26'],
            'sort' => ['sometimes', Rule::in(['name', '-name', 'reference', '-reference', '-created_at'])],
        ]);

        $query = Product::query()->with(['category', 'images' => fn ($q) => $q->where('position', 0)]);

        if (isset($validated['q'])) {
            // Référence, nom, SKU ou code-barres exact (sous-requêtes portées par les modèles : portée d'entreprise incluse).
            $term = '%'.addcslashes((string) $validated['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('name', 'like', $term)
                ->orWhereIn('id', ProductVariant::query()->where('sku', 'like', $term)->select('product_id'))
                ->orWhereIn('id', ProductVariant::query()->whereIn('id', VariantUnit::query()->where('barcode', $validated['q'])->select('variant_id'))->select('product_id')));
        }

        $query->where('status', $validated['filter']['status'] ?? ProductStatus::Active->value);

        if (isset($validated['filter']['category_id'])) {
            $category = Category::query()->findOrFail((string) $validated['filter']['category_id']);
            $query->whereIn('category_id', Category::query()->where('path', 'like', $category->path.'%')->select('id'));
        }

        $sort = $validated['sort'] ?? 'name';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return Paginated::response(
            $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']),
            fn (Product $p) => CatalogPresenter::product($p, withVariants: false),
        );
    }

    public function show(string $product): JsonResponse
    {
        return new JsonResponse(['data' => CatalogPresenter::product($this->load($product))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'product_type' => ['sometimes', Rule::in(['stock', 'service'])],
            'base_unit_label' => ['sometimes', 'string', 'max:16'],
            'pieces_per_carton' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'carton_barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants' => ['sometimes', 'array', 'max:200'],
            ...self::variantRules('variants.*.'),
        ]);

        $product = $this->products->create($validated, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::product($this->load($product->id))], 201);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        $this->load($product);
        $validated = $request->validate([
            'reference' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'product_type' => ['sometimes', Rule::in(['stock', 'service'])],
            'base_unit_label' => ['sometimes', 'string', 'max:16'],
        ]);

        $ifMatch = $request->header('If-Match');
        $version = is_string($ifMatch) && preg_match('/^"?(\d+)"?$/', $ifMatch, $m) === 1 ? (int) $m[1] : null;

        $this->products->update($this->load($product)->id, $validated, $version, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::product($this->load($product))]);
    }

    public function destroy(string $product): JsonResponse
    {
        $this->products->delete($this->load($product)->id);

        return new JsonResponse(null, 204);
    }

    public function archive(Request $request, string $product): JsonResponse
    {
        return $this->status($request, $product, ProductStatus::Archived);
    }

    public function activate(Request $request, string $product): JsonResponse
    {
        return $this->status($request, $product, ProductStatus::Active);
    }

    public function deactivate(Request $request, string $product): JsonResponse
    {
        return $this->status($request, $product, ProductStatus::Inactive);
    }

    public function uploadImage(Request $request, string $product, ProductImageService $images): JsonResponse
    {
        $this->load($product);
        $validated = $request->validate([
            'image' => ['required', 'file', 'max:5120'],
            'variant_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $image = $images->upload($this->load($product)->id, $request->file('image'), $validated['variant_id'] ?? null, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::image($image)], 201);
    }

    public function deleteImage(string $image, ProductImageService $images): JsonResponse
    {
        $images->delete(ProductImage::query()->findOrFail($image)->id);

        return new JsonResponse(null, 204);
    }

    public function imageFile(string $image, PrivateFileStorage $files): StreamedResponse
    {
        return $files->inline($files->findForCurrentCompany(ProductImage::query()->findOrFail($image)->file_id));
    }

    public function imageThumbnail(string $image, PrivateFileStorage $files): StreamedResponse
    {
        $row = ProductImage::query()->findOrFail($image);

        return $files->inline($files->findForCurrentCompany($row->thumb_file_id ?? $row->file_id));
    }

    /** @return array<string, list<mixed>> */
    public static function variantRules(string $prefix): array
    {
        $required = $prefix === '' ? 'required' : 'required_with:variants';

        return [
            $prefix.'sku' => [$required, 'string', 'max:64'],
            $prefix.'label' => ['sometimes', 'string', 'max:160'],
            $prefix.'attribute_value_ids' => ['sometimes', 'array', 'max:10'],
            $prefix.'attribute_value_ids.*' => ['string', 'size:26'],
            $prefix.'pieces_per_carton' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            $prefix.'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            $prefix.'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            $prefix.'carton_barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    private function status(Request $request, string $product, ProductStatus $status): JsonResponse
    {
        $this->products->changeStatus($this->load($product)->id, $status, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::product($this->load($product))]);
    }

    private function load(string $id): Product
    {
        return Product::query()->with(['category', 'images', 'variants.units', 'variants.attributeValues'])->findOrFail($id);
    }
}
