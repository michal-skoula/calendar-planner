<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Task truth. Owned by TaskService.
            $table->string('title');
            $table->text('notes')->nullable();
            $table->json('tags')->default('[]');
            $table->date('deadline')->nullable();
            $table->unsignedSmallInteger('duration_min')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Calendar-bearing fields. Written ONLY by TaskCalendarBackend's three
            // overrides — see app/Dav/TaskCalendarBackend.php. No model observers.
            $table->string('status')->default('inbox'); // inbox | scheduled | done
            $table->string('ical_uid')->nullable()->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_all_day')->default(false);

            $table->timestamps();

            $table->index('status');
            $table->index('starts_at');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
