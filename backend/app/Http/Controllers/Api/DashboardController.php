<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = Carbon::today(config('app.timezone'));

        $todayStats = Sale::completed()
            ->whereDate('created_at', $today)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as sales_count')
            ->first();

        $lowStockCount = Product::lowStock()->count();

        $recentTransactions = Sale::with(['user:id,name'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (Sale $sale) {
                return [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'total_amount' => $sale->total_amount,
                    'payment_method' => $sale->payment_method,
                    'status' => $sale->status,
                    'cashier_name' => $sale->user?->name,
                    'items_count' => $sale->items_count,
                    'created_at' => $sale->created_at,
                ];
            });

        return response()->json([
            'data' => [
                'today_revenue' => number_format((float) $todayStats->revenue, 2, '.', ''),
                'today_sales_count' => (int) $todayStats->sales_count,
                'low_stock_count' => $lowStockCount,
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }
}
