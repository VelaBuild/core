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

// The names this route may not answer to, each one a route of its own.
$velaTakenSlugs = [
    preg_quote($adminPrefix, '#'),
    preg_quote($authPrefix, '#'),
    'login', 'logout', 'register', 'password', 'home', 'profile', 'two-factor',
];

// Anchored on purpose. Without the $ the lookahead refused every slug that
// merely BEGINS with one of these, and a page called "home-…" is exactly what
// this CMS makes when it displaces a homepage: "install as a new page" wrote
// home-1 and the page it had just made answered 404, and the copies kept so a
// homepage could be put back could not be opened to see what was in them.
Route::get('/{slug}', [PublicControllers\PageController::class, 'show'])
    ->name('pages.show')
    ->where('slug', '(?!(?:' . implode('|', $velaTakenSlugs) . ')$)[a-z0-9]+(?:-[a-z0-9]+)*');
