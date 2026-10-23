<?php

use App\Http\Controllers\LoginController;
use App\Livewire\Planner;
use App\Livewire\SyncActivity;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/', Planner::class)->middleware('auth')->name('planner');
Route::get('/sync', SyncActivity::class)->middleware('auth')->name('sync');

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
