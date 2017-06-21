<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateFlightsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('airline_id')->unsigned();
            $table->text('flight_number');
            $table->text('route_code')->nullable();
            $table->text('route_leg')->nullable();
            $table->integer('dpt_airport_id')->unsigned();
            $table->integer('arr_airport_id')->unsigned();
            $table->integer('alt_airport_id')->unsigned()->nullable();
            $table->text('route')->nullable();
            $table->text('dpt_time')->nullable();
            $table->text('arr_time')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('flight_number');

            $table->index('flight_number');
            $table->index('dpt_airport_id');
            $table->index('arr_airport_id');
        });

        Schema::create('flight_aircraft', function ($table) {
            $table->increments('id');
            $table->integer('flight_id')->unsigned();
            $table->integer('aircraft_id')->unsigned();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('flights');
        Schema::drop('flight_aircraft');
    }
}