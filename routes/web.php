<?php

use App\Http\Controllers\LoginController;
use App\Livewire\Planner;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/', Planner::class)->middleware('auth')->name('planner');

/*
| RFC 6764 discovery. Apple Calendar looks here first when you type just the
| hostname into "Add CalDAV Account", so this is what makes the short form work.
| It sits outside the DAV route because laravel-sabre only serves /dav.
*/
Route::redirect('/.well-known/caldav', '/dav', 301);
Route::redirect('/.well-known/carddav', '/dav', 301);
