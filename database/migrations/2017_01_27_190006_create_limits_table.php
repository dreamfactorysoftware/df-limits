<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLimitsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //Create limits table.
        Schema::create(
            'limits',
            function (Blueprint $t){
                $t->increments('id')->unsigned();
                $t->string('type', 50);
                $t->string('key_text', 100)->unique();
                $t->integer('rate');
                $t->integer('period');
                $t->integer('user_id')->unsigned()->nullable();
                $t->foreign('user_id')->references('id')->on('user');
                $t->integer('role_id')->unsigned()->nullable();
                $t->foreign('role_id')->references('id')->on('role');
                $t->integer('service_id')->unsigned()->nullable();
                $t->foreign('service_id')->references('id')->on('service');
                $t->string('name');
                $t->string('description')->nullable();
                $t->tinyInteger('is_active')->default(1);
                $t->timestamp('created_date')->nullable();
                $t->timestamp('last_modified_date')->useCurrent();
            }
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('limits');
    }
}
