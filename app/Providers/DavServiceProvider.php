<?php

namespace App\Providers;

use App\Dav\CalDavPasswordBackend;
use App\Dav\DavLogPlugin;
use App\Dav\DavPathAliasPlugin;
use App\Dav\TaskCalendar;
use App\Dav\TaskCalendarBackend;
use App\Dav\VEventMapper;
use App\Services\SyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LaravelSabre\LaravelSabre;
use Sabre\CalDAV\CalendarRoot;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Browser\Plugin as BrowserPlugin;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\DAVACL\PrincipalCollection;

class DavServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, or the client named at beforeMethod would not
        // be the one the backend reads when it records what changed.
        $this->app->singleton(SyncLog::class);

        $this->app->singleton(VEventMapper::class);
        $this->app->singleton(TaskCalendar::class);
        $this->app->singleton(TaskCalendarBackend::class);
        $this->app->singleton(CalDavPasswordBackend::class);

        $this->app->singleton(
            PrincipalBackend::class,
            fn (): PrincipalBackend => new PrincipalBackend(DB::connection()->getPdo()),
        );
    }

    public function boot(): void
    {
        LaravelSabre::nodes(fn (): array => [
            new PrincipalCollection($this->app->make(PrincipalBackend::class)),
            new CalendarRoot(
                $this->app->make(PrincipalBackend::class),
                $this->app->make(TaskCalendarBackend::class),
            ),
        ]);

        LaravelSabre::plugins(function (): \Generator {
            // One log line per request. Its hooks carry explicit priorities, so it
            // sees both the unauthenticated probe and the status finally answered.
            yield new DavLogPlugin;

            // Before auth, so a misaddressed request is redirected rather than
            // challenged for credentials it would then be told are wrong.
            yield new DavPathAliasPlugin;

            // Auth first: ACL needs a principal to reason about.
            yield new AuthPlugin($this->app->make(CalDavPasswordBackend::class));

            $acl = new AclPlugin;
            $acl->allowUnauthenticatedAccess = false;
            yield $acl;

            yield new CalDavPlugin;

            // WebDAV-Sync. Apple uses sync-collection rather than re-reading the
            // whole calendar, so without this every poll is a full download.
            yield new SyncPlugin;

            if ($this->app->environment('local')) {
                yield new BrowserPlugin(false);
            }
        });
    }
}
