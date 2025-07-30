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
        Schema::create('schedule_route_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->unique();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->unsignedBigInteger('tracker_id');
            $table->enum('frequency', ['every_x_weeks', 'every_x_months']);
            $table->unsignedInteger('frequency_value');
            $table->json('days_of_week');
            $table->tinyInteger('weekday_ordinal')->nullable();
            $table->boolean('is_valid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->date('start_date');
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
        Schema::dropIfExists('schedule_route_tasks');
    }
};
