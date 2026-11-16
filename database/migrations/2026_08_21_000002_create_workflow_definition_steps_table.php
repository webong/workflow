<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definition_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_definition_id')
                ->constrained('workflow_definitions')
                ->cascadeOnDelete();
            $table->string('step_id', 150);
            $table->string('label', 255);
            $table->boolean('critical')->default(true);
            $table->boolean('retriable')->default(false);
            $table->json('depends_on')->nullable();
            $table->json('metadata')->nullable();
            $table->json('retry_policy')->nullable();
            $table->unsignedInteger('order_column')->default(1);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'step_id']);
            $table->index(['workflow_definition_id', 'order_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definition_steps');
    }
};
