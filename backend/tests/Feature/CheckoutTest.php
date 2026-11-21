<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;

test('checkout success: totals correct to the cent, stock decremented, sale_items and stock_movements written', function () {
    $cashier = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $product1 = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'ITEM-01',
        'price' => 15.50,
        'stock_quantity' => 20,
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'ITEM-02',
        'price' => 10.00,
        'stock_quantity' => 10,
    ]);

    $response = $this->actingAs($cashier)
        ->postJson('/api/sales', [
            'payment_method' => 'card',
            'discount_percent' => 0,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2], // 31.00
                ['product_id' => $product2->id, 'quantity' => 1], // 10.00
            ],
        ]);

    // Subtotal: 41.00
    // Tax: 41.00 * 0.08 = 3.28
    // Total: 44.28
    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Sale completed successfully.',
            'data' => [
                'total_amount' => '44.28',
                'discount_amount' => '0.00',
                'tax_amount' => '3.28',
                'payment_method' => 'card',
                'status' => 'completed',
            ],
        ]);

    $saleId = $response->json('data.id');
    $invoiceNo = $response->json('data.invoice_number');

    expect($invoiceNo)->toMatch('/^NV-\d{8}-[A-Z0-9]{6}$/');

    // Stock decremented
    expect($product1->fresh()->stock_quantity)->toBe(18)
        ->and($product2->fresh()->stock_quantity)->toBe(9);

    // Sale items written
    $this->assertDatabaseHas('sale_items', [
        'sale_id' => $saleId,
        'product_id' => $product1->id,
        'quantity' => 2,
        'unit_price' => '15.50',
        'subtotal' => '31.00',
    ]);

    $this->assertDatabaseHas('sale_items', [
        'sale_id' => $saleId,
        'product_id' => $product2->id,
        'quantity' => 1,
        'unit_price' => '10.00',
        'subtotal' => '10.00',
    ]);

    // Stock movements written
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product1->id,
        'type' => 'out',
        'quantity' => 2,
        'reference_note' => "Sale {$invoiceNo}",
    ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product2->id,
        'type' => 'out',
        'quantity' => 1,
        'reference_note' => "Sale {$invoiceNo}",
    ]);
});

test('discount and tax math with known example (subtotal 10.00, discount 10%, tax 8% => total 9.72)', function () {
    $cashier = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 10.00,
        'stock_quantity' => 10,
    ]);

    $response = $this->actingAs($cashier)
        ->postJson('/api/sales', [
            'payment_method' => 'cash',
            'discount_percent' => 10,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'discount_amount' => '1.00',
                'tax_amount' => '0.72',
                'total_amount' => '9.72',
            ],
        ]);
});

test('checkout with duplicate product lines is merged correctly', function () {
    $cashier = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 12.00,
        'stock_quantity' => 20,
    ]);

    $response = $this->actingAs($cashier)
        ->postJson('/api/sales', [
            'payment_method' => 'cash',
            'discount_percent' => 0,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

    $response->assertStatus(201);
    $items = $response->json('data.items');

    // Only 1 merged item line with quantity 5
    expect($items)->toHaveCount(1)
        ->and($items[0]['quantity'])->toBe(5)
        ->and($items[0]['subtotal'])->toBe('60.00');

    expect($product->fresh()->stock_quantity)->toBe(15);
});

test('checkout with insufficient stock returns 422 and NO partial writes', function () {
    $cashier = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $product1 = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'SUFFICIENT-01',
        'price' => 10.00,
        'stock_quantity' => 5,
    ]);

    $product2 = Product::factory()->create([
        'category_id' => $category->id,
        'sku' => 'INSUFFICIENT-01',
        'price' => 20.00,
        'stock_quantity' => 2,
    ]);

    $response = $this->actingAs($cashier)
        ->postJson('/api/sales', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 3], // valid
                ['product_id' => $product2->id, 'quantity' => 5], // requires 5, has only 2!
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['items']);

    expect($response->json('errors.items.0'))->toContain('INSUFFICIENT-01');

    // Verify atomic rollback: no sale created, stock unchanged, no movements
    expect(Sale::count())->toBe(0)
        ->and($product1->fresh()->stock_quantity)->toBe(5)
        ->and($product2->fresh()->stock_quantity)->toBe(2)
        ->and(StockMovement::count())->toBe(0);
});

test('checkout with a soft-deleted product returns 422', function () {
    $cashier = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 10,
    ]);

    $product->delete();
    expect($product->trashed())->toBeTrue();

    $response = $this->actingAs($cashier)
        ->postJson('/api/sales', [
            'payment_method' => 'card',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

    $response->assertStatus(422);
    expect(Sale::count())->toBe(0);
});

test('cashier sees only own sales; admin sees all; cashier gets 403 on another users sale', function () {
    $cashier1 = User::factory()->cashier()->create();
    $cashier2 = User::factory()->cashier()->create();
    $admin = User::factory()->admin()->create();

    $sale1 = Sale::create([
        'user_id' => $cashier1->id,
        'invoice_number' => 'NV-20261114-111111',
        'total_amount' => '50.00',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $sale2 = Sale::create([
        'user_id' => $cashier2->id,
        'invoice_number' => 'NV-20261114-222222',
        'total_amount' => '75.00',
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'payment_method' => 'card',
        'status' => 'completed',
    ]);

    // Cashier 1 listing sales sees only sale1
    $resCashier1 = $this->actingAs($cashier1)->getJson('/api/sales');
    $resCashier1->assertStatus(200);
    $cashier1SaleIds = collect($resCashier1->json('data'))->pluck('id')->all();
    expect($cashier1SaleIds)->toContain($sale1->id)
        ->and($cashier1SaleIds)->not->toContain($sale2->id);

    // Admin listing sales sees both
    $resAdmin = $this->actingAs($admin)->getJson('/api/sales');
    $resAdmin->assertStatus(200);
    $adminSaleIds = collect($resAdmin->json('data'))->pluck('id')->all();
    expect($adminSaleIds)->toContain($sale1->id)
        ->and($adminSaleIds)->toContain($sale2->id);

    // Cashier 1 viewing own sale succeeds
    $this->actingAs($cashier1)
        ->getJson("/api/sales/{$sale1->id}")
        ->assertStatus(200);

    // Cashier 1 viewing Cashier 2's sale returns 403 (never 404 leakage)
    $this->actingAs($cashier1)
        ->getJson("/api/sales/{$sale2->id}")
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden.']);

    // Admin viewing Cashier 2's sale succeeds
    $this->actingAs($admin)
        ->getJson("/api/sales/{$sale2->id}")
        ->assertStatus(200);
});
