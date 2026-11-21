<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $query = Product::with('category');

        if ($request->filled('search')) {
            $search = $this->escapeLikeWildcards((string) $request->input('search'));
            $searchTerm = "%{$search}%";
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw("name LIKE ? ESCAPE '\\'", [$searchTerm])
                    ->orWhereRaw("sku LIKE ? ESCAPE '\\'", [$searchTerm]);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        $products = $query->orderBy('name')->paginate($perPage);

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category');

        return response()->json([
            'data' => $product,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $product = DB::transaction(function () use ($validated, $request) {
            $product = Product::create($validated);

            $stockQuantity = (int) ($validated['stock_quantity'] ?? 0);

            if ($stockQuantity > 0) {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'in',
                    'quantity' => $stockQuantity,
                    'reference_note' => 'Initial stock on product creation',
                ]);
            }

            return $product;
        });

        $product->load('category');

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $product, $request) {
            $oldStock = $product->stock_quantity;

            if (isset($validated['stock_quantity'])) {
                $newStock = (int) $validated['stock_quantity'];
                $product = Product::lockForUpdate()->findOrFail($product->id);
                $oldStock = $product->stock_quantity;

                $product->update($validated);

                $diff = $newStock - $oldStock;
                if ($diff !== 0) {
                    StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => $request->user()->id,
                        'type' => 'adjustment',
                        'quantity' => $diff,
                        'reference_note' => 'Stock updated via product edit',
                    ]);
                }
            } else {
                $product->update($validated);
            }
        });

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $product->fresh('category'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    public function adjustStock(AdjustStockRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        $quantity = (int) $validated['quantity'];

        $product = DB::transaction(function () use ($product, $quantity, $validated, $request) {
            /** @var Product $product */
            $product = Product::lockForUpdate()->findOrFail($product->id);

            $newStock = $product->stock_quantity + $quantity;

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ["Insufficient stock. Current stock is {$product->stock_quantity}, cannot adjust by {$quantity}."],
                ]);
            }

            $product->update(['stock_quantity' => $newStock]);

            StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'adjustment',
                'quantity' => $quantity,
                'reference_note' => $validated['reference_note'],
            ]);

            return $product;
        });

        return response()->json([
            'message' => 'Stock adjusted successfully.',
            'data' => $product->fresh(),
        ]);
    }

    private function escapeLikeWildcards(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}
