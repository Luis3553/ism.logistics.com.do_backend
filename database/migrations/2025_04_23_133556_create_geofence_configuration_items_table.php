<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('geofence_configuration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geofence_configuration_id')->constrained('geofence_configurations')->onDelete('cascade');
            $table->unsignedBigInteger('geofence_id');
            $table->string('type')->enum('origin', 'destiny');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('geofence_configuration_items');
    }
};
