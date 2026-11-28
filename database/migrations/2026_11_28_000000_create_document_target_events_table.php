<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('laravel-documents.coalescing.connection'))->create('document_target_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('target_key', 64);
            $table->string('type');
            $table->timestamp('created_at');
            $table->index(['target_key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('laravel-documents.coalescing.connection'))->dropIfExists('document_target_events');
    }
};
