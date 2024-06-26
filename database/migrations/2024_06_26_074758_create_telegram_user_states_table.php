<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_user_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id');
            $table->boolean('is_allowed')->default(false);
            $table->string('state')->nullable();
            $table->timestamps();

            $table->foreign('telegram_user_id')->references('id')->on('telegram_users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_user_states');
    }
};
