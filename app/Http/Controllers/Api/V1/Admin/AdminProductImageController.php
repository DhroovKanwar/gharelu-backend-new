<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\ReorderProductImagesRequest;
use App\Http\Requests\Admin\Product\StoreProductImageRequest;
use App\Http\Resources\Admin\AdminProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminProductImageController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        // ->store() generates a random hashed filename — the original
        // client-supplied filename is never trusted or used for the path.
        $path = $request->file('image')->store('products', 'public');
        $isPrimary = (bool) ($data['is_primary'] ?? false);

        $image = DB::transaction(function () use ($product, $path, $data, $isPrimary) {
            if ($isPrimary) {
                $product->images()->update(['is_primary' => false]);
            }

            return $product->images()->create([
                'path' => $path,
                'alt' => $data['alt'] ?? null,
                'sort_order' => ($product->images()->max('sort_order') ?? -1) + 1,
                'is_primary' => $isPrimary,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product image uploaded successfully.',
            'data' => new AdminProductImageResource($image),
        ], 201);
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->assertBelongsToProduct($product, $image);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product image deleted successfully.',
            'data' => null,
        ]);
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product): JsonResponse
    {
        $orderedIds = $request->validated('order');
        $ownedCount = $product->images()->whereIn('id', $orderedIds)->count();

        if ($ownedCount !== count($orderedIds)) {
            throw ValidationException::withMessages([
                'order' => ['One or more images do not belong to this product.'],
            ]);
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $imageId) {
                ProductImage::where('id', $imageId)->update(['sort_order' => $index]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Product images reordered successfully.',
            'data' => AdminProductImageResource::collection($product->images()->get()),
        ]);
    }

    private function assertBelongsToProduct(Product $product, ProductImage $image): void
    {
        if ($image->product_id !== $product->id) {
            abort(404);
        }
    }
}
