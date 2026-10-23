<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // RFC 6764 discovery. Clients issue PROPFIND (not just GET) against the
        // well-known URI and expect a redirect, and a CalDAV client has no CSRF
        // token to offer. The /dav endpoint itself carries no session middleware
        // at all — see config/laravelsabre.php.
        $middleware->validateCsrfTokens(except: [
            '.well-known/*',
        ]);

        // Caddy terminates TLS and is the only thing that can reach the
        // container, which publishes no host port. Without this the
        // /.well-known/caldav redirect comes back as http:// and a device
        // following it leaves TLS behind.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
