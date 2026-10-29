<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
| RFC 6764 discovery. Apple Calendar looks here first when you type just the
| hostname into "Add CalDAV Account", so this is what makes the short form work.
| It sits outside the DAV route because laravel-sabre only serves /dav.
*/
Route::redirect('/.well-known/caldav', '/dav', 301);
Route::redirect('/.well-known/carddav', '/dav', 301);

/*
| Apple's client invents two more paths when the server address carries one of
| its own; DavPathAliasPlugin absorbs them inside the DAV stack, because
| laravel-sabre's `dav/{path?}` catch-all is registered before this file and
| would swallow any route declared here.
*/

/*
| Everything else is the web app's to route: /, /login, /sync, /calendars.
| A fallback only runs when no other route matched, so /api, /dav, /mcp and
| /up are never shadowed by it.
*/
Route::fallback(SpaController::class);
