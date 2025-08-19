<?php

namespace Litepie\Flow\database\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlowStatesTable extends Migration
{
    public function up()
    {
        Schema::create('flow_states', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->boolean('initial')->default(false);
            $table->boolean('final')->default(false);
            $table->foreignId('workflow_id')->constrained('flow_workflows')->onDelete('cascade');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_states');
    }
}
