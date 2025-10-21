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
        Schema::create('flow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('from');
            $table->string('to');
            $table->string('event');
            $table->string('label')->nullable();
            $table->foreignId('workflow_id')->constrained('flow_workflows')->onDelete('cascade');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes with descriptive names
            $table->index('from', 'flow_transitions_from_idx');
            $table->index('to', 'flow_transitions_to_idx');
            $table->index('event', 'flow_transitions_event_idx');
            $table->index('label', 'flow_transitions_label_idx');
            $table->index(['workflow_id', 'from', 'to'], 'flow_transitions_workflow_from_to_idx');
            $table->index(['workflow_id', 'event'], 'flow_transitions_workflow_event_idx');
            $table->index('created_at', 'flow_transitions_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_transitions');
    }
};
