<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendars someone else owns, subscribed to by URL and drawn read-only.
 *
 * Nothing here relates to `tasks`, and nothing here is a sabre table. A feed is
 * never written back to, so none of the reconciliation that keeps `tasks` and
 * `calendarobjects` in step applies — see app/Dav/TaskCalendarBackend.php for
 * the rule this feature deliberately stays clear of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Encrypted, because a Google or iCloud feed URL is a bearer
            // credential: holding it is reading the calendar. That makes the
            // column unindexable, so duplicates are caught on the hash instead.
            $table->text('url');
            $table->string('url_hash', 64)->unique();

            $table->string('color', 9)->default('#4f6d9a');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('refresh_min')->default(60);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // The last body that parsed as a calendar. A failed fetch never
            // touches these four, which is what keeps the grid populated while
            // someone else's server is down.
            $table->text('payload')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->unsignedInteger('payload_bytes')->nullable();
            $table->unsignedSmallInteger('series_count')->nullable();

            // Series the expander could not walk. Shown per feed, so a calendar
            // that is quietly missing a recurring event says so.
            $table->unsignedSmallInteger('dropped_count')->nullable();

            // Conditional GET state. Most feeds answer 304 to these, which is
            // the difference between a 2 MB download every 15 minutes and none.
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();

            $table->timestamp('last_fetched_at')->nullable();   // any attempt
            $table->timestamp('last_success_at')->nullable();    // a 200 or a 304
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->index(['is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
