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
        Schema::create('flow_states', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->boolean('initial')->default(false);
            $table->boolean('final')->default(false);
            $table->foreignId('workflow_id')->constrained('flow_workflows')->onDelete('cascade');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes with descriptive names
            $table->index('name', 'flow_states_name_idx');
            $table->index('label', 'flow_states_label_idx');
            $table->index('initial', 'flow_states_initial_idx');
            $table->index('final', 'flow_states_final_idx');
            $table->index(['workflow_id', 'name'], 'flow_states_workflow_name_idx');
            $table->index('created_at', 'flow_states_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_states');
    }
};
