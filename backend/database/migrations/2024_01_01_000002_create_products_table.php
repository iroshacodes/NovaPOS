<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 150)->index();
            $table->string('sku', 64)->unique();
            $table->decimal('price', 10, 2);
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->string('image_url', 500)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['stock_quantity', 'low_stock_threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
