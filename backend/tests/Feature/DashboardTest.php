<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Carbon;

test('dashboard returns correct aggregates', function () {
    $admin = User::factory()->admin()->create(['name' => 'Store Manager']);
    $cashier = User::factory()->cashier()->create(['name' => 'John Cashier']);
    $category = Category::factory()->create();

    // Create products with various stock levels
    Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 2,
        'low_stock_threshold' => 5, // low stock!
    ]);
    Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 5,
        'low_stock_threshold' => 5, // low stock!
    ]);
    Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 20,
        'low_stock_threshold' => 5, // normal stock
    ]);

    // Sales today
    $sale1 = Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'NV-20261114-AAA111',
        'total_amount' => '100.50',
        'discount_amount' => '0.00',
        'tax_amount' => '8.04',
        'payment_method' => 'card',
        'status' => 'completed',
        'created_at' => Carbon::now(config('app.timezone')),
    ]);

    $sale2 = Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'NV-20261114-BBB222',
        'total_amount' => '50.25',
        'discount_amount' => '0.00',
        'tax_amount' => '4.02',
        'payment_method' => 'cash',
        'status' => 'completed',
        'created_at' => Carbon::now(config('app.timezone')),
    ]);

    // Refunded sale today (should NOT be in today_revenue)
    Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'NV-20261114-CCC333',
        'total_amount' => '200.00',
        'discount_amount' => '0.00',
        'tax_amount' => '16.00',
        'payment_method' => 'card',
        'status' => 'refunded',
        'created_at' => Carbon::now(config('app.timezone')),
    ]);

    // Sale from yesterday (should NOT be in today_revenue)
    $yesterdaySale = Sale::create([
        'user_id' => $cashier->id,
        'invoice_number' => 'NV-20261113-DDD444',
        'total_amount' => '300.00',
        'discount_amount' => '0.00',
        'tax_amount' => '24.00',
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);
    $yesterdaySale->created_at = Carbon::yesterday(config('app.timezone'));
    $yesterdaySale->save();

    // Add sale items to sale1
    SaleItem::create([
        'sale_id' => $sale1->id,
        'product_id' => 1,
        'quantity' => 3,
        'unit_price' => '30.00',
        'subtotal' => '90.00',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/api/dashboard');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'today_revenue',
                'today_sales_count',
                'low_stock_count',
                'recent_transactions',
            ],
        ]);

    // Total revenue today = 100.50 + 50.25 = 150.75
    expect($response->json('data.today_revenue'))->toBe('150.75')
        ->and($response->json('data.today_sales_count'))->toBe(2)
        ->and($response->json('data.low_stock_count'))->toBe(2);

    $transactions = $response->json('data.recent_transactions');
    expect($transactions)->toBeArray()
        ->and(count($transactions))->toBeGreaterThanOrEqual(2);

    $firstTx = collect($transactions)->firstWhere('invoice_number', 'NV-20261114-AAA111');
    expect($firstTx['cashier_name'])->toBe('John Cashier')
        ->and($firstTx['items_count'])->toBe(1);
});
