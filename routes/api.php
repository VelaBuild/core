<?php

use VelaBuild\Core\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

// Read endpoints
Route::get('/', [McpController::class, 'index'])->name('index');
Route::get('/posts', [McpController::class, 'posts'])->name('posts');
Route::get('/posts/{slug}', [McpController::class, 'post'])->name('posts.show');
Route::get('/pages', [McpController::class, 'pages'])->name('pages');
Route::get('/pages/{slug}', [McpController::class, 'page'])->name('pages.show');
Route::get('/categories', [McpController::class, 'categories'])->name('categories');
Route::get('/settings', [McpController::class, 'settings'])->name('settings');
Route::get('/settings/{group}', [McpController::class, 'settingsGroup'])->name('settings.show');

// Write endpoints — stricter rate limits
Route::put('/settings/{group}', [McpController::class, 'updateSettings'])->name('settings.update')
    ->middleware('throttle:10,1');
Route::delete('/cache/{type}', [McpController::class, 'clearCache'])->name('cache.clear')
    ->middleware('throttle:5,1');
