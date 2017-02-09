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
                $t->bigIncrements('id')->unsigned();
                $t->string('type', 50);
                $t->string('key_text')->unique();
                $t->integer('rate');
                $t->integer('period');
                $t->mediumInteger('user_id')->nullable();
                $t->mediumInteger('role_id')->nullable();
                $t->mediumInteger('service_id')->nullable();
                $t->string('name');
                $t->string('label')->nullable();
                $t->tinyInteger('is_active')->default(1);
                $t->timestamp('created_date')->nullable();
                $t->timestamp('last_modified_date')->nullable();
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
