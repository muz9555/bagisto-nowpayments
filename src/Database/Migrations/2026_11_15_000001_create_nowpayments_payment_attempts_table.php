<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create persistent payment references and settlement records.
     */
    public function up(): void
    {
        Schema::create('nowpayments_payment_attempts', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('reference')->unique();
            $table->unsignedInteger('cart_id')->unique();
            $table->unsignedInteger('order_id')->unique();
            $table->string('channel_code');
            $table->boolean('sandbox');
            $table->decimal('price_amount', 16, 4);
            $table->string('price_currency', 10);
            $table->string('invoice_id')->nullable()->unique();
            $table->string('payment_id')->nullable()->unique();
            $table->text('invoice_url')->nullable();
            $table->string('status')->default('created')->index();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    /**
     * Remove payment tracking records.
     */
    public function down(): void
    {
        Schema::dropIfExists('nowpayments_payment_attempts');
    }
};
