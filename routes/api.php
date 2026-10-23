<?php

use App\Http\Controllers\Api\TaskIngestController;
use Illuminate\Support\Facades\Route;

/*
| The iOS Shortcut endpoint. Bearer token from `php artisan planner:token`.
*/
Route::post('/tasks', TaskIngestController::class)
    ->middleware('auth:sanctum')
    ->name('api.tasks.store');
