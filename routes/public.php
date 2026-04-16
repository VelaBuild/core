<?php

use VelaBuild\Core\Http\Controllers\Public as PublicControllers;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicControllers\HomeController::class, 'index'])->name('home');
Route::get('/posts', [PublicControllers\PostController::class, 'index'])->name('posts.index');
Route::get('/posts/{slug}', [PublicControllers\PostController::class, 'show'])->name('posts.show');
Route::get('/categories', [PublicControllers\CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{slug}', [PublicControllers\CategoryController::class, 'show'])->name('categories.show');
Route::post('/page-form/{page}', [PublicControllers\PageController::class, 'submitForm'])->name('page-form.submit');

// Catch-all page route — MUST be last
$adminPrefix = config('vela.admin_prefix', 'admin');
$authPrefix = config('vela.auth_prefix', 'vela');
Route::get('/{slug}', [PublicControllers\PageController::class, 'show'])
    ->name('pages.show')
    ->where('slug', '(?!' . $adminPrefix . '|' . $authPrefix . '|login|logout|register|password|home|profile|two-factor)[a-z0-9]+(?:-[a-z0-9]+)*');
