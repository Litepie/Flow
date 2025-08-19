<?php

namespace Litepie\Flow\database\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlowTransitionsTable extends Migration
{
    public function up()
    {
        Schema::create('flow_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('from');
            $table->string('to');
            $table->string('event');
            $table->string('label')->nullable();
            $table->foreignId('workflow_id')->constrained('flow_workflows')->onDelete('cascade');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_transitions');
    }
}
