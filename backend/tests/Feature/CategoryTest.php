<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

test('cashier cannot create category (403)', function () {
    $cashier = User::factory()->cashier()->create();

    $response = $this->actingAs($cashier)
        ->postJson('/api/categories', [
            'name' => 'Snacks',
            'slug' => 'snacks',
            'description' => 'Yummy treats',
        ]);

    $response->assertStatus(403)
        ->assertJson(['message' => 'Forbidden.']);
});

test('admin can create category (201)', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/api/categories', [
            'name' => 'Desserts',
            'slug' => 'desserts',
            'description' => 'Sweet delights',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Category created successfully.',
            'data' => [
                'name' => 'Desserts',
                'slug' => 'desserts',
            ],
        ]);

    $this->assertDatabaseHas('categories', ['slug' => 'desserts']);
});

test('categories list returns products_count', function () {
    $user = User::factory()->cashier()->create();
    $cat = Category::factory()->create(['name' => 'Drinks', 'slug' => 'drinks']);
    Product::factory()->count(3)->create(['category_id' => $cat->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/categories');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'slug', 'products_count'],
            ],
        ]);

    $data = collect($response->json('data'));
    $drinksCat = $data->firstWhere('id', $cat->id);
    expect($drinksCat['products_count'])->toBe(3);
});

test('admin can update category', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

    $response = $this->actingAs($admin)
        ->putJson("/api/categories/{$category->id}", [
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Category updated successfully.',
            'data' => [
                'id' => $category->id,
                'name' => 'New Name',
                'slug' => 'new-name',
            ],
        ]);
});

test('category delete refused when products reference it', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    $response = $this->actingAs($admin)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot delete category. It has associated products.',
        ]);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('category delete refused when soft-deleted products reference it', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $product->delete();

    expect($product->trashed())->toBeTrue();

    $response = $this->actingAs($admin)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot delete category. It has associated products.',
        ]);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('category delete succeeds when no products reference it', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $response = $this->actingAs($admin)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Category deleted successfully.',
        ]);

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});
