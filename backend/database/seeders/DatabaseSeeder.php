<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@novapos.test'],
            [
                'name' => 'Admin User',
                'password' => 'password',
                'role' => 'admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'cashier@novapos.test'],
            [
                'name' => 'Cashier User',
                'password' => 'password',
                'role' => 'cashier',
            ]
        );

        // Categories
        $beverages = Category::firstOrCreate(
            ['slug' => 'beverages'],
            ['name' => 'Beverages', 'description' => 'Hot and cold drinks']
        );

        $bakery = Category::firstOrCreate(
            ['slug' => 'bakery'],
            ['name' => 'Bakery', 'description' => 'Fresh baked goods']
        );

        $tech = Category::firstOrCreate(
            ['slug' => 'tech'],
            ['name' => 'Tech', 'description' => 'Technology accessories']
        );

        $snacks = Category::firstOrCreate(
            ['slug' => 'snacks'],
            ['name' => 'Snacks', 'description' => 'Snacks and treats']
        );

        // Products
        $products = [
            ['category_id' => $beverages->id, 'name' => 'Cold Brew Coffee', 'sku' => 'BEV-001', 'price' => 4.50, 'stock_quantity' => 40],
            ['category_id' => $beverages->id, 'name' => 'Matcha Latte', 'sku' => 'BEV-002', 'price' => 5.25, 'stock_quantity' => 25],
            ['category_id' => $beverages->id, 'name' => 'Sparkling Lemonade', 'sku' => 'BEV-003', 'price' => 3.00, 'stock_quantity' => 3],
            ['category_id' => $bakery->id, 'name' => 'Butter Croissant', 'sku' => 'BAK-001', 'price' => 3.25, 'stock_quantity' => 30],
            ['category_id' => $bakery->id, 'name' => 'Sourdough Loaf', 'sku' => 'BAK-002', 'price' => 6.00, 'stock_quantity' => 12],
            ['category_id' => $bakery->id, 'name' => 'Blueberry Muffin', 'sku' => 'BAK-003', 'price' => 2.75, 'stock_quantity' => 4],
            ['category_id' => $tech->id, 'name' => 'Wireless Earbuds', 'sku' => 'TEC-001', 'price' => 59.99, 'stock_quantity' => 8],
            ['category_id' => $tech->id, 'name' => '30W USB-C Charger', 'sku' => 'TEC-002', 'price' => 24.99, 'stock_quantity' => 15],
            ['category_id' => $tech->id, 'name' => 'Smartwatch Band', 'sku' => 'TEC-003', 'price' => 19.50, 'stock_quantity' => 2],
            ['category_id' => $snacks->id, 'name' => 'Sea Salt Popcorn', 'sku' => 'SNK-001', 'price' => 2.99, 'stock_quantity' => 35],
            ['category_id' => $snacks->id, 'name' => 'Dark Chocolate Bar', 'sku' => 'SNK-002', 'price' => 3.49, 'stock_quantity' => 20],
            ['category_id' => $snacks->id, 'name' => 'Trail Mix Pack', 'sku' => 'SNK-003', 'price' => 4.25, 'stock_quantity' => 14],
        ];

        foreach ($products as $productData) {
            $stockQty = $productData['stock_quantity'];

            $product = Product::firstOrCreate(
                ['sku' => $productData['sku']],
                $productData
            );

            // Create initial stock movement if product was just created
            if ($product->wasRecentlyCreated && $stockQty > 0) {
                StockMovement::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'reference_note' => 'Initial stock on seeding',
                    ],
                    [
                        'user_id' => $admin->id,
                        'type' => 'in',
                        'quantity' => $stockQty,
                    ]
                );
            }
        }
    }
}
