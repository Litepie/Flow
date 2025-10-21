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
        Schema::create('flow_executions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_name');
            $table->string('state_from');
            $table->string('state_to');
            
            // Model (polymorphic) - explicitly defined to avoid naming conflicts
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            
            $table->json('context')->nullable();
            $table->timestamps();
            
            // Explicit indexes with globally unique names
            $table->index(['model_type', 'model_id'], 'flow_executions_model_morph_idx');
            $table->index('workflow_name', 'flow_executions_workflow_name_idx');
            $table->index(['state_from', 'state_to'], 'flow_executions_state_transition_idx');
            $table->index('created_at', 'flow_executions_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_executions');
    }
};
