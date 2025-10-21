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
        Schema::create('flow_state_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_name');
            $table->string('state');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->json('properties')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            // Explicit indexes with globally unique names
            $table->index(['model_type', 'model_id'], 'flow_state_histories_model_morph_idx');
            $table->index(['causer_type', 'causer_id'], 'flow_state_histories_causer_morph_idx');
            $table->index('workflow_name', 'flow_state_histories_workflow_name_idx');
            $table->index('state', 'flow_state_histories_state_idx');
            $table->index('changed_at', 'flow_state_histories_changed_at_idx');
            $table->index('created_at', 'flow_state_histories_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_state_histories');
    }
};
