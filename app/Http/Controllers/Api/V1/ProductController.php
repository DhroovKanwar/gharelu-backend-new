<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    private const EAGER = ['category', 'sizes', 'images'];

    public function index(ProductIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $perPage = $data['per_page'] ?? 20;

        $query = Product::query()
            ->with(self::EAGER)
            ->where('is_active', true);

        if (! empty($data['category'])) {
            $query->whereHas('category', function ($q) use ($data) {
                $q->where('slug', $data['category']);
            });
        }

        if (array_key_exists('featured', $data)) {
            $query->where('is_featured', (bool) $data['featured']);
        }

        if (array_key_exists('bestseller', $data)) {
            $query->where('is_bestseller', (bool) $data['bestseller']);
        }

        if (! empty($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }

        $products = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => ProductResource::collection($products)->collection,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->with(self::EAGER)
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
                'data' => null,
            ], 404);
        }

        $related = Product::query()
            ->with(self::EAGER)
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->where(function ($q) use ($product) {
                $q->where('category_id', $product->category_id)
                    ->orWhere('collection', $product->collection);
            })
            ->orderBy('id')
            ->limit(3)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully.',
            'data' => new ProductResource($product),
            'relatedProducts' => ProductResource::collection($related),
        ]);
    }

    public function featured(): JsonResponse
    {
        $products = Cache::remember('products:featured', now()->addMinutes(10), function () {
            return Product::query()
                ->with(self::EAGER)
                ->where('is_active', true)
                ->where('is_featured', true)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'message' => 'Featured products retrieved successfully.',
            'data' => ProductResource::collection($products),
        ]);
    }

    public function bestsellers(): JsonResponse
    {
        $products = Cache::remember('products:bestsellers', now()->addMinutes(10), function () {
            return Product::query()
                ->with(self::EAGER)
                ->where('is_active', true)
                ->where('is_bestseller', true)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'message' => 'Bestseller products retrieved successfully.',
            'data' => ProductResource::collection($products),
        ]);
    }
}
