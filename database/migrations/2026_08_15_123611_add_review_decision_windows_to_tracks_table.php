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
        Schema::table('tracks', function (Blueprint $table) {
            $table->timestamp('review_open_at')->nullable();
            $table->timestamp('review_close_at')->nullable();
            $table->timestamp('decision_open_at')->nullable();
            $table->timestamp('decision_close_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['review_open_at', 'review_close_at', 'decision_open_at', 'decision_close_at']);
        });
    }
};
