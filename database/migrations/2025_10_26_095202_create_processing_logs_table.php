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
        Schema::create('processing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->nullable()->constrained('emails')->onDelete('cascade');
            $table->enum('type', ['email_fetch', 'email_process', 'excel_extract', 'html_extract', 'error'])->default('email_process');
            $table->enum('status', ['started', 'success', 'failed'])->default('started');
            $table->text('message')->nullable();
            $table->json('context')->nullable(); // Additional context data
            $table->text('error_trace')->nullable();
            $table->timestamps();

            $table->index(['email_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_logs');
    }
};
