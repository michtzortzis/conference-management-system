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
        Schema::table('conferences', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('submission_files', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conferences', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('submission_files', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
