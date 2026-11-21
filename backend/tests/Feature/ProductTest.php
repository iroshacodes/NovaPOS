<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;

test('cashier cannot create product (403), admin can', function () {
    $category = Category::factory()->create();
    $cashier = User::factory()->cashier()->create();
    $admin = User::factory()->admin()->create();

    $payload = [
        'category_id' => $category->id,
        'name' => 'Matcha Tea',
        'sku' => 'mat-001',
        'price' => 5.50,
        'cost_price' => 2.00,
        'stock_quantity' => 20,
        'low_stock_threshold' => 5,
        'image_url' => 'https://example.com/tea.jpg',
    ];

    $this->actingAs($cashier)
        ->postJson('/api/products', $payload)
        ->assertStatus(403);

    $response = $this->actingAs($admin)
        ->postJson('/api/products', $payload);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Product created successfully.',
            'data' => [
                'sku' => 'MAT-001', // Normalized to uppercase
                'name' => 'Matcha Tea',
            ],
        ]);

    // Initial stock movement created
    $this->assertDatabaseHas('stock_movements', [
        'type' => 'in',
        'quantity' => 20,
        'user_id' => $admin->id,
    ]);
});

test('sku is normalized to trimmed uppercase in validation', function () {
    $category = Category::factory()->create();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Test Item',
            'sku' => '  abc-xyz-123  ',
            'price' => 12.00,
        ]);

    $response->assertStatus(201);
    expect($response->json('data.sku'))->toBe('ABC-XYZ-123');
});

test('product search escapes % and _ wildcards', function () {
    $user = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    Product::factory()->create(['category_id' => $category->id, 'name' => '100% Orange Juice', 'sku' => 'OJ-100']);
    Product::factory()->create(['category_id' => $category->id, 'name' => 'Apple Juice', 'sku' => 'AJ-01']);
    Product::factory()->create(['category_id' => $category->id, 'name' => 'A_B Item', 'sku' => 'AB-01']);
    Product::factory()->create(['category_id' => $category->id, 'name' => 'AXB Item', 'sku' => 'AB-02']);

    // Searching for "%" should match only the literal "100% Orange Juice", not all products
    $resPercent = $this->actingAs($user)
        ->getJson('/api/products?search=%');

    $resPercent->assertStatus(200);
    $dataPercent = collect($resPercent->json('data'));
    expect($dataPercent->pluck('name')->all())->toContain('100% Orange Juice')
        ->and($dataPercent->pluck('name')->all())->not->toContain('Apple Juice');

    // Searching for "_" should match only literal "A_B Item", not "AXB Item"
    $resUnderscore = $this->actingAs($user)
        ->getJson('/api/products?search=_');

    $resUnderscore->assertStatus(200);
    $dataUnderscore = collect($resUnderscore->json('data'));
    expect($dataUnderscore->pluck('name')->all())->toContain('A_B Item')
        ->and($dataUnderscore->pluck('name')->all())->not->toContain('AXB Item');
});

test('product filter low_stock returns products with stock <= threshold', function () {
    $user = User::factory()->cashier()->create();
    $category = Category::factory()->create();

    $low = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Low Stock Product',
        'stock_quantity' => 3,
        'low_stock_threshold' => 5,
    ]);

    $normal = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Normal Stock Product',
        'stock_quantity' => 20,
        'low_stock_threshold' => 5,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/products?low_stock=1');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($low->id)
        ->and($ids)->not->toContain($normal->id);
});

test('updating product stock_quantity writes an adjustment movement with signed diff', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 15,
    ]);

    $response = $this->actingAs($admin)
        ->putJson("/api/products/{$product->id}", [
            'stock_quantity' => 25,
        ]);

    $response->assertStatus(200);
    expect($product->fresh()->stock_quantity)->toBe(25);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'adjustment',
        'quantity' => 10,
        'user_id' => $admin->id,
    ]);
});

test('adjust-stock cannot make stock negative (422)', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 5,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/products/{$product->id}/adjust-stock", [
            'quantity' => -10,
            'reference_note' => 'Damaged inventory',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);

    expect($product->fresh()->stock_quantity)->toBe(5);
});

test('adjust-stock succeeds and writes adjustment movement with signed quantity', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'stock_quantity' => 10,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/products/{$product->id}/adjust-stock", [
            'quantity' => -3,
            'reference_note' => 'Inventory write-off',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Stock adjusted successfully.',
            'data' => [
                'id' => $product->id,
                'stock_quantity' => 7,
            ],
        ]);

    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'type' => 'adjustment',
        'quantity' => -3,
        'reference_note' => 'Inventory write-off',
        'user_id' => $admin->id,
    ]);
});

test('cashier cannot adjust stock (403)', function () {
    $cashier = User::factory()->cashier()->create();
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $this->actingAs($cashier)
        ->postJson("/api/products/{$product->id}/adjust-stock", [
            'quantity' => 5,
            'reference_note' => 'Test',
        ])
        ->assertStatus(403);
});

test('deleting a product soft-deletes it and preserves movements', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['stock_quantity' => 10]);

    StockMovement::create([
        'product_id' => $product->id,
        'user_id' => $admin->id,
        'type' => 'in',
        'quantity' => 10,
        'reference_note' => 'Initial',
    ]);

    $response = $this->actingAs($admin)
        ->deleteJson("/api/products/{$product->id}");

    $response->assertStatus(200)
        ->assertJson(['message' => 'Product deleted successfully.']);

    expect($product->fresh()->trashed())->toBeTrue();

    // Stock movement is preserved
    $this->assertDatabaseHas('stock_movements', [
        'product_id' => $product->id,
        'quantity' => 10,
    ]);
});
