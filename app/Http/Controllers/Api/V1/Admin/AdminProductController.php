<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\AdminProductIndexRequest;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Admin\AdminProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    private const EAGER = ['category', 'sizes', 'images'];

    public function index(AdminProductIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $perPage = $data['per_page'] ?? 20;

        $query = Product::query()->with(self::EAGER);

        if (array_key_exists('category_id', $data)) {
            $query->where('category_id', $data['category_id']);
        }
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }
        if (array_key_exists('is_featured', $data)) {
            $query->where('is_featured', (bool) $data['is_featured']);
        }
        if (array_key_exists('is_bestseller', $data)) {
            $query->where('is_bestseller', (bool) $data['is_bestseller']);
        }
        if (! empty($data['stock_status'])) {
            $query->where('stock_status', $data['stock_status']);
        }
        if (! empty($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }

        $products = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => AdminProductResource::collection($products)->collection,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->resolveSlug($data['slug'] ?? null, $data['name']);

        $product = Product::create($data);
        $product->load(self::EAGER);

        $this->forgetCatalogCache();

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => new AdminProductResource($product),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(self::EAGER);

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data' => new AdminProductResource($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('slug', $data) && empty($data['slug'])) {
            $data['slug'] = $this->resolveSlug(null, $data['name'] ?? $product->name, $product->id);
        }

        $product->update($data);
        $product->load(self::EAGER);

        $this->forgetCatalogCache();

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => new AdminProductResource($product),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Soft delete — order_items keep their own price/name/image
        // snapshot already, so this never affects historical orders; it
        // just removes the product from every "live" catalog query.
        $product->delete();

        $this->forgetCatalogCache();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
            'data' => null,
        ]);
    }

    private function resolveSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug ?: $name);
        $candidate = $base;
        $suffix = 2;

        while (
            Product::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function forgetCatalogCache(): void
    {
        Cache::forget('products:featured');
        Cache::forget('products:bestsellers');
    }
}
