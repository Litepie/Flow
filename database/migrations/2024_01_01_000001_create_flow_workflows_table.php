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
        Schema::create('flow_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes with descriptive names
            $table->unique('name', 'flow_workflows_name_unique');
            $table->index('label', 'flow_workflows_label_idx');
            $table->index('created_at', 'flow_workflows_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_workflows');
    }
};
