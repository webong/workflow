<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 150);
            $table->unsignedInteger('version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definitions');
    }
};
