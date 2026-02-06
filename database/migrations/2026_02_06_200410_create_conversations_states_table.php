<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations_states', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->unique();
            $table->enum('state', ['free', 'creating_task', 'updating_task', 'deleting_task', 'reading_task'])->default('free');
            $table->jsonb('payload')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations_states');
    }
};
