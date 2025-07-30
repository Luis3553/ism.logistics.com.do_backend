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
        Schema::table('schedule_route_tasks', function (Blueprint $table) {
            $table->unsignedInteger('ocurrence_limit')->nullable();
            $table->unsignedInteger('ocurrence_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('schedule_route_tasks', function (Blueprint $table) {
            $table->dropColumn(['ocurrence_limit', 'ocurrence_count']);
        });
    }
};
