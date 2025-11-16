<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->nullable()->constrained('emails')->onDelete('cascade');
            $table->string('carrier')->nullable();
            $table->string('service_type')->nullable();
            $table->string('origin_country')->nullable();
            $table->string('origin_city')->nullable();
            $table->string('origin_port')->nullable();
            $table->string('destination_country')->nullable();
            $table->string('destination_city')->nullable();
            $table->string('destination_port')->nullable();
            $table->decimal('rate', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('container_type')->nullable(); // 20ft, 40ft, 40HC
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('remarks')->nullable();
            $table->json('additional_charges')->nullable(); // For other fees
            $table->json('raw_data')->nullable(); // Store original extracted data
            $table->timestamps();

            $table->index(['carrier', 'origin_port', 'destination_port', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_cards');
    }
};
