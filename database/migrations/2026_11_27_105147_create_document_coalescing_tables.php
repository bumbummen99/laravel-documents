<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_coalescing_targets', function (Blueprint $table): void {
            $table->string('target_key', 64)->primary();
            $table->string('latest_snapshot')->nullable();
            $table->string('latest_fingerprint', 64)->nullable();
            $table->string('published_fingerprint', 64)->nullable();
            $table->longText('pending')->nullable();
            $table->string('worker_token')->nullable();
            $table->timestamp('lease_until')->nullable()->index();
            $table->string('queue_connection')->nullable();
            $table->string('queue_name')->nullable();
        });

        Schema::create('document_coalescing_stages', function (Blueprint $table): void {
            $table->string('version')->primary();
            $table->string('target_key', 64);
            $table->boolean('discarding')->default(false);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_coalescing_stages');
        Schema::dropIfExists('document_coalescing_targets');
    }
};
