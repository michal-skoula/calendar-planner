<?php

namespace App\Dav;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Redirects the paths Apple's client invents when the server address carries a
 * path of its own.
 *
 * Typing `host/dav` into macOS's "Server Address" field makes it resolve
 * discovery against that path: it asks for `/dav/.well-known/caldav`, and then,
 * once the root hands back an href of `/dav/principals/...`, for
 * `/dav/dav/principals/...`. Both 404 inside sabre, and macOS reports the whole
 * exchange as "Unable to verify account name or password" — so a working
 * credential looks broken and the search goes to entirely the wrong place.
 *
 * The root collection holds only `principals` and `calendars`, so neither prefix
 * can ever be a real child and absorbing them costs nothing.
 *
 * This lives here rather than in routes/web.php because laravel-sabre's
 * `dav/{path?}` catch-all is registered first and would swallow any route
 * declared later.
 */
final class DavPathAliasPlugin extends ServerPlugin
{
    private ?Server $server = null;

    public function initialize(Server $server): void
    {
        $this->server = $server;

        // Priority 5: after the log plugin's 1, before authentication's 10. A
        // redirect to the right path should not first demand credentials.
        $server->on('beforeMethod:*', [$this, 'onBeforeMethod'], 5);
    }

    public function getPluginName(): string
    {
        return 'planner-path-alias';
    }

    public function onBeforeMethod(RequestInterface $request, ResponseInterface $response): ?bool
    {
        $path = trim($request->getPath(), '/');

        $target = match (true) {
            $path === 'dav' => '',
            str_starts_with($path, 'dav/') => substr($path, 4),
            str_starts_with($path, '.well-known/') => '',
            default => null,
        };

        if ($target === null) {
            return null;
        }

        $response->setStatus(301);
        $response->setHeader('Location', rtrim((string) $this->server?->getBaseUri(), '/').'/'.$target);

        return false;
    }
}
