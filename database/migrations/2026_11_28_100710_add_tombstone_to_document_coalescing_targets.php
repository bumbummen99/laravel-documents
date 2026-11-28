<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_coalescing_targets', function (Blueprint $table): void {
            $table->boolean('tombstoned')->default(false);
            $table->string('published_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_coalescing_targets', function (Blueprint $table): void {
            $table->dropColumn('tombstoned');
            $table->dropColumn('published_version');
        });
    }
};
