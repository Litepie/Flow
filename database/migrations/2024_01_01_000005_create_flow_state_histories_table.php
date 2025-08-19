<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('flow_state_histories', function (Blueprint $table) {
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
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_state_histories');
    }
};
