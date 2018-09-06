<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCreditCardsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        if (!Schema::hasTable('credit_cards')) {
            Schema::create('credit_cards', function (Blueprint $table) {
                $table->string('id');
                $table->integer('organization_id')->unsigned();
                $table->integer('user_id')->unsigned();
                $table->boolean('primary')->default(false);
                $table->string('number', 8);
                $table->string('type');
                $table->dateTime('expires_at');

                $table->timestamps();
                $table->softDeletes();

                $table->primary('id');

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('user_id')->references('id')->on('users');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('credit_cards');
    }
}
