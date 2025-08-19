<?php

namespace Litepie\Flow\database\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlowExecutionsTable extends Migration
{
    public function up()
    {
        Schema::create('flow_executions', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_name');
            $table->string('state_from');
            $table->string('state_to');
            $table->morphs('model');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_executions');
    }
}
