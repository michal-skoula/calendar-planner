<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the built web app for any path no other route claimed.
 *
 * The web app is a separate project (apps/web). In production its build is
 * copied into public/ by the Dockerfile, so the page and the API share an
 * origin and the session cookie needs no CORS. In development you run the Vite
 * server instead, which proxies /api here.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // An unknown API path is a 404 in JSON, never the app's HTML.
        if ($request->is('api/*')) {
            abort(404);
        }

        $index = public_path('index.html');

        if (! is_file($index)) {
            return response(
                "The web app is not built into this server.\n\n"
                ."In development, run `npm run dev` at the repository root and open http://localhost:5180.\n",
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        // no-cache so a deploy is picked up on the next load. The hashed
        // files under /assets it points at are what get cached.
        return response(file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
