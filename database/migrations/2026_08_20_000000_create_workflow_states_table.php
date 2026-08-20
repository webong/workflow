<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_states', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 100);
            $table->string('subject_id', 191);
            $table->string('flow_key', 150);
            $table->json('state');
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->boolean('initialized')->default(true);
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'flow_key']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_states');
    }
};
