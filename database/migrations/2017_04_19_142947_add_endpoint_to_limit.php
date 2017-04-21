<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEndpointToLimit extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('limits', function (Blueprint $t) {
            $t->dropForeign('limits_role_id_foreign');
            $t->dropForeign('limits_service_id_foreign');
            $t->dropForeign('limits_user_id_foreign');
            $t->dropIndex('limits_role_id_foreign');
            $t->dropIndex('limits_service_id_foreign');
            $t->dropIndex('limits_user_id_foreign');
            /** Add new columns */
            $t->text('endpoint')->nullable();
            $t->enum('verb', array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'))->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('limits', function (Blueprint $table) {
            //
        });
    }
}
