<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id');
            $table->enum('status', ['draft', 'active', 'done', 'canceled', 'deleted'])->default('draft');
            $table->string('title', length: 255);
            $table->string('description', length: 1000)->nullable();
            $table->timestamp('deadline_at', precision: 0);
            $table->timestamps();

            $table->foreign('telegram_user_id')
                ->references('telegram_user_id')
                ->on('conversations_states')
                ->onUpdate('restrict')
                ->onDelete('cascade');

            $table->index(['telegram_user_id', 'status']);
            $table->index(['deadline_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
