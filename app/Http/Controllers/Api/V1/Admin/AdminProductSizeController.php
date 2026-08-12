<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\ReorderProductSizesRequest;
use App\Http\Requests\Admin\Product\StoreProductSizeRequest;
use App\Http\Requests\Admin\Product\UpdateProductSizeRequest;
use App\Http\Resources\Admin\AdminProductSizeResource;
use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminProductSizeController extends Controller
{
    public function store(StoreProductSizeRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        $size = $product->sizes()->create([
            'label' => $data['label'],
            'price' => $data['price'],
            'sort_order' => ($product->sizes()->max('sort_order') ?? -1) + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product size added successfully.',
            'data' => new AdminProductSizeResource($size),
        ], 201);
    }

    public function update(UpdateProductSizeRequest $request, Product $product, ProductSize $size): JsonResponse
    {
        $this->assertBelongsToProduct($product, $size);

        $size->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product size updated successfully.',
            'data' => new AdminProductSizeResource($size),
        ]);
    }

    public function destroy(Product $product, ProductSize $size): JsonResponse
    {
        $this->assertBelongsToProduct($product, $size);

        $size->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product size deleted successfully.',
            'data' => null,
        ]);
    }

    public function reorder(ReorderProductSizesRequest $request, Product $product): JsonResponse
    {
        $orderedIds = $request->validated('order');
        $ownedCount = $product->sizes()->whereIn('id', $orderedIds)->count();

        if ($ownedCount !== count($orderedIds)) {
            throw ValidationException::withMessages([
                'order' => ['One or more sizes do not belong to this product.'],
            ]);
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $sizeId) {
                ProductSize::where('id', $sizeId)->update(['sort_order' => $index]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Product sizes reordered successfully.',
            'data' => AdminProductSizeResource::collection($product->sizes()->get()),
        ]);
    }

    private function assertBelongsToProduct(Product $product, ProductSize $size): void
    {
        if ($size->product_id !== $product->id) {
            abort(404);
        }
    }
}
