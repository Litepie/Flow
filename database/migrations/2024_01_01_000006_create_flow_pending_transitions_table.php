<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('flow_pending_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_name');
            $table->string('transition');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->json('context')->nullable();
            $table->timestamp('scheduled_for');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_pending_transitions');
    }
};
