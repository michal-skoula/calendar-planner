<?php

use App\Http\Controllers\Api\AccessTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Middleware\RequireBrowserSession;
use Illuminate\Support\Facades\Route;

/*
| The REST API. Every path here is described in openapi.yaml, and
| tests/Feature/Api/OpenApiContractTest fails if one is missing from it.
|
| Everything is prefixed /api by bootstrap/app.php.
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/tokens', [AuthController::class, 'token'])->middleware('throttle:10,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/revision', [SyncController::class, 'revision']);

    // POST /api/tasks is also the iOS Shortcut's endpoint.
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store'])->name('api.tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{task}/schedule', [TaskController::class, 'schedule']);
    Route::post('/tasks/{task}/unschedule', [TaskController::class, 'unschedule']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen']);

    Route::get('/feeds', [FeedController::class, 'index']);
    Route::post('/feeds', [FeedController::class, 'store']);
    Route::patch('/feeds/{feed}', [FeedController::class, 'update']);
    Route::delete('/feeds/{feed}', [FeedController::class, 'destroy']);
    Route::post('/feeds/{feed}/refresh', [FeedController::class, 'refresh']);
    Route::get('/feed-events', [FeedController::class, 'events']);

    Route::get('/sync/clients', [SyncController::class, 'clients']);
    Route::get('/sync/events', [SyncController::class, 'events']);

    // Bearer tokens. Managed from a browser session only, so a leaked token
    // cannot mint itself a replacement.
    Route::middleware(RequireBrowserSession::class)->group(function () {
        Route::get('/tokens', [AccessTokenController::class, 'index']);
        Route::post('/tokens', [AccessTokenController::class, 'store']);
        Route::delete('/tokens/{token}', [AccessTokenController::class, 'destroy'])->whereNumber('token');
    });
});
