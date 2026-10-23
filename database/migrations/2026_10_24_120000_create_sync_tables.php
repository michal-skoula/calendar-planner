<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the planner knows about syncing, as opposed to what it writes to stderr.
 *
 * The container log answers "why did this request fail" and is the right tool
 * for that. Neither question it cannot answer — "is my phone still talking to
 * this thing" and "what actually changed" — survives a container restart, so
 * both get a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per client, upserted. It never grows with traffic, which is
        // what makes it safe to touch on every single DAV request.
        Schema::create('sync_clients', function (Blueprint $table) {
            $table->id();
            $table->string('agent')->unique();
            $table->timestamp('last_seen_at');
            $table->string('last_method', 16)->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();
        });

        // Only things worth reading: a task changed, or something went wrong.
        // Polls are not events, or the list would be nothing but noise.
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('source', 16);
            $table->string('client')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            // Snapshotted, so an event still reads properly once its task is gone.
            $table->string('title')->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
        Schema::dropIfExists('sync_clients');
    }
};
