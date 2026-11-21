<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $user = $request->user();

        $query = Sale::with(['user:id,name', 'items']);

        // Cashiers can only see their own sales
        if ($user->isCashier()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $sales = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($sales);
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        // Cashiers can only view their own sales
        if ($user->isCashier() && $sale->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $sale->load(['user:id,name', 'items.product:id,name,sku']);

        return response()->json([
            'data' => $sale,
        ]);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $discountPercent = (float) ($validated['discount_percent'] ?? 0);
        $paymentMethod = $validated['payment_method'];
        $taxRateBasisPoints = (int) config('pos.tax_rate');

        // Step 1: Merge duplicate product lines
        $mergedItems = [];
        foreach ($validated['items'] as $item) {
            $productId = (int) $item['product_id'];
            if (isset($mergedItems[$productId])) {
                $mergedItems[$productId]['quantity'] += (int) $item['quantity'];
            } else {
                $mergedItems[$productId] = [
                    'product_id' => $productId,
                    'quantity' => (int) $item['quantity'],
                ];
            }
        }

        $mergedItems = array_values($mergedItems);

        // Sort by product_id to prevent deadlocks
        usort($mergedItems, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        $sale = DB::transaction(function () use ($mergedItems, $user, $discountPercent, $paymentMethod, $taxRateBasisPoints) {
            // Step 2: Load products with lockForUpdate, ordered by id
            $productIds = array_column($mergedItems, 'product_id');
            $products = Product::lockForUpdate()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            // Step 3: Validate stock availability
            foreach ($mergedItems as $item) {
                $product = $products->get($item['product_id']);

                if (! $product || $product->trashed()) {
                    throw ValidationException::withMessages([
                        'items' => ["Product with ID {$item['product_id']} is not available."],
                    ]);
                }

                if ($product->stock_quantity < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$product->sku}. Available: {$product->stock_quantity}, requested: {$item['quantity']}."],
                    ]);
                }
            }

            // Step 5: Money math in integer cents
            $totalSubtotalCents = 0;
            $totalDiscountCents = 0;
            $totalTaxCents = 0;
            $saleItemsData = [];

            foreach ($mergedItems as $item) {
                $product = $products->get($item['product_id']);
                $quantity = $item['quantity'];

                // Convert price to cents
                $priceCents = (int) round((float) $product->price * 100);
                $lineSubtotalCents = $priceCents * $quantity;

                // Discount: round(subtotal_cents * discount_percent / 100) half up
                $lineDiscountCents = (int) round($lineSubtotalCents * $discountPercent / 100);

                // Taxable = subtotal - discount
                $taxableCents = $lineSubtotalCents - $lineDiscountCents;

                // Tax: round(taxable * tax_rate) where tax_rate is basis points / 10000
                $lineTaxCents = (int) round($taxableCents * $taxRateBasisPoints / 10000);

                $totalSubtotalCents += $lineSubtotalCents;
                $totalDiscountCents += $lineDiscountCents;
                $totalTaxCents += $lineTaxCents;

                $saleItemsData[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => number_format($priceCents / 100, 2, '.', ''),
                    'subtotal' => number_format($lineSubtotalCents / 100, 2, '.', ''),
                ];
            }

            // Total = subtotal - discount + tax
            $totalAmountCents = $totalSubtotalCents - $totalDiscountCents + $totalTaxCents;

            // Step 7: Generate unique invoice number
            $invoiceNumber = $this->generateInvoiceNumber();

            // Step 6: Insert sale
            $sale = Sale::create([
                'user_id' => $user->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => number_format($totalAmountCents / 100, 2, '.', ''),
                'discount_amount' => number_format($totalDiscountCents / 100, 2, '.', ''),
                'tax_amount' => number_format($totalTaxCents / 100, 2, '.', ''),
                'payment_method' => $paymentMethod,
                'status' => 'completed',
            ]);

            // Insert sale items, decrement stock, create stock movements
            foreach ($saleItemsData as $index => $itemData) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'subtotal' => $itemData['subtotal'],
                ]);

                $product = $products->get($itemData['product_id']);

                // Decrement stock
                $product->decrement('stock_quantity', $itemData['quantity']);

                // Stock movement
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'out',
                    'quantity' => $itemData['quantity'],
                    'reference_note' => "Sale {$invoiceNumber}",
                ]);
            }

            return $sale;
        });

        $sale->load('items.product:id,name,sku');

        return response()->json([
            'message' => 'Sale completed successfully.',
            'data' => $sale,
        ], 201);
    }

    private function generateInvoiceNumber(): string
    {
        $date = now()->format('Ymd');
        $maxAttempts = 5;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $random = strtoupper(Str::random(6));
            $invoiceNumber = "NV-{$date}-{$random}";

            if (! Sale::where('invoice_number', $invoiceNumber)->exists()) {
                return $invoiceNumber;
            }
        }

        // This should be extremely rare
        throw new \RuntimeException('Failed to generate unique invoice number after '.$maxAttempts.' attempts.');
    }
}
