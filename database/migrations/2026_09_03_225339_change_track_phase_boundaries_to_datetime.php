<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The six track phase boundaries are date-granular: an `*_open_at` is read as the
 * start of its day and a `*_close_at` as the end of it (see
 * Track::phaseOpensAt / Track::phaseClosesAt), and phase overrides write plain
 * wall-clock days into them. MySQL `timestamp` converts values UTC<->session on
 * every read and write, so a boundary would be correct only while the DB server's
 * `system_time_zone` matches `app.timezone`; a server on another zone, a future
 * `app.timezone` change or a DST boundary silently shifts every stored boundary
 * by hours — enough to move a date-only CFP close a whole day earlier or later.
 * `datetime` stores the wall value with no conversion, which deletes that failure
 * class instead of documenting it.
 */
return new class extends Migration
{
    /**
     * The six phase boundaries, in ordering-chain order.
     *
     * @var list<string>
     */
    private const BOUNDARIES = [
        'submission_open_at',
        'submission_close_at',
        'review_open_at',
        'review_close_at',
        'decision_open_at',
        'decision_close_at',
    ];

    /**
     * Run the migrations.
     *
     * Deliberately no `SET time_zone` here: MySQL converts a `timestamp` column by
     * keeping the wall value its current session renders, so changing the session
     * timezone mid-migration would shift every existing boundary by that offset.
     * Running on the unmodified application connection — the same one that wrote
     * the rows — is what keeps each row's value byte-identical.
     */
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            foreach (self::BOUNDARIES as $column) {
                // change() re-specifies the whole column, so nullable() must be restated.
                $table->datetime($column)->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            foreach (self::BOUNDARIES as $column) {
                $table->timestamp($column)->nullable()->change();
            }
        });
    }
};
