<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sabre/dav's stock schema, column for column.
 *
 * These tables belong to sabre, not to this application. Nothing outside
 * App\Dav\TaskCalendarBackend may read or write them, and no Eloquent model is
 * mapped onto them. Renaming a column here breaks the vendored PDO backends.
 *
 * `calendarsubscriptions` is required even though this application never exposes
 * a subscription: the stock backend implements SubscriptionSupport, and
 * CalDAV\CalendarHome::getChildren() calls getSubscriptionsForUser() on every
 * listing of the calendar home. Without the table, Apple cannot enumerate the
 * calendar at all.
 *
 * `schedulingobjects` is required for the same reason. The stock backend also
 * implements SchedulingSupport, so CalendarHome always advertises an inbox and an
 * outbox, and Apple PROPFINDs both while setting an account up. Without the table
 * those two collections answer 500, which iOS reports to the user as nothing more
 * useful than an inactive account. Sabre\CalDAV\Schedule\Plugin is still not
 * loaded, so there is no RFC 6638 scheduling or invite handling in v1: the
 * collections simply enumerate empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table) {
            $table->increments('id');
            $table->text('uri');
            $table->text('email')->nullable();
            $table->text('displayname')->nullable();

            $table->unique('uri');
        });

        Schema::create('groupmembers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('principal_id');
            $table->integer('member_id');

            $table->unique(['principal_id', 'member_id']);
        });

        Schema::create('calendars', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('synctoken')->default(1);
            $table->text('components');
        });

        Schema::create('calendarinstances', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('calendarid');
            $table->text('principaluri')->nullable();
            $table->integer('access')->default(1); // 1 = owner, 2 = read, 3 = readwrite
            $table->text('displayname')->nullable();
            $table->text('uri');
            $table->text('description')->nullable();
            $table->integer('calendarorder')->nullable();
            $table->text('calendarcolor')->nullable();
            $table->text('timezone')->nullable();
            $table->boolean('transparent')->nullable();
            $table->text('share_href')->nullable();
            $table->text('share_displayname')->nullable();
            $table->integer('share_invitestatus')->default(2);

            $table->unique(['principaluri', 'uri']);
            $table->unique(['calendarid', 'principaluri']);
            $table->unique(['calendarid', 'share_href']);
        });

        Schema::create('calendarobjects', function (Blueprint $table) {
            $table->increments('id');
            $table->binary('calendardata');
            $table->text('uri');
            $table->integer('calendarid');
            $table->integer('lastmodified')->nullable();
            $table->text('etag')->nullable();
            $table->integer('size')->nullable();
            $table->text('componenttype')->nullable();
            $table->integer('firstoccurence')->nullable();
            $table->integer('lastoccurence')->nullable();
            $table->text('uid')->nullable();

            $table->unique(['calendarid', 'uri']);
            $table->index(['calendarid', 'firstoccurence'], 'calendarid_time');
        });

        Schema::create('calendarsubscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->text('uri');
            $table->text('principaluri');
            $table->text('source');
            $table->text('displayname')->nullable();
            $table->text('refreshrate')->nullable();
            $table->integer('calendarorder')->nullable();
            $table->text('calendarcolor')->nullable();
            $table->boolean('striptodos')->nullable();
            $table->boolean('stripalarms')->nullable();
            $table->boolean('stripattachments')->nullable();
            $table->integer('lastmodified')->nullable();

            $table->unique(['principaluri', 'uri']);
        });

        Schema::create('schedulingobjects', function (Blueprint $table) {
            $table->increments('id');
            $table->text('principaluri');
            $table->binary('calendardata')->nullable();
            $table->text('uri');
            $table->integer('lastmodified')->nullable();
            $table->text('etag')->nullable();
            $table->integer('size')->nullable();

            $table->unique(['principaluri', 'uri']);
        });

        Schema::create('calendarchanges', function (Blueprint $table) {
            $table->increments('id');
            $table->text('uri')->nullable();
            $table->integer('synctoken');
            $table->integer('calendarid');
            $table->integer('operation');

            $table->index(['calendarid', 'synctoken'], 'calendarid_synctoken');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendarchanges');
        Schema::dropIfExists('calendarsubscriptions');
        Schema::dropIfExists('schedulingobjects');
        Schema::dropIfExists('calendarobjects');
        Schema::dropIfExists('calendarinstances');
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('groupmembers');
        Schema::dropIfExists('principals');
    }
};
