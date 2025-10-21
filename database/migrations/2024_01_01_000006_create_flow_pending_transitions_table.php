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
        Schema::create('flow_pending_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_name');
            $table->string('transition');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->json('context')->nullable();
            $table->timestamp('scheduled_for');
            $table->string('status')->default('pending');
            $table->timestamps();

            // Explicit indexes with globally unique names
            $table->index(['model_type', 'model_id'], 'flow_pending_transitions_model_morph_idx');
            $table->index('workflow_name', 'flow_pending_transitions_workflow_name_idx');
            $table->index('transition', 'flow_pending_transitions_transition_idx');
            $table->index('scheduled_for', 'flow_pending_transitions_scheduled_for_idx');
            $table->index('status', 'flow_pending_transitions_status_idx');
            $table->index(['status', 'scheduled_for'], 'flow_pending_transitions_status_scheduled_idx');
            $table->index('created_at', 'flow_pending_transitions_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_pending_transitions');
    }
};
