<?php

use Illuminate\Support\Facades\Route;
use VelaBuild\Core\Http\Controllers\Admin;

Route::get('/', [Admin\HomeController::class, 'index'])->name('home');
Route::post('dashboard/preferences', [Admin\HomeController::class, 'savePreferences'])->name('dashboard.preferences');

// Permissions
Route::delete('permissions/destroy', [Admin\PermissionsController::class, 'massDestroy'])->name('permissions.massDestroy');
Route::resource('permissions', Admin\PermissionsController::class);

// Roles
Route::delete('roles/destroy', [Admin\RolesController::class, 'massDestroy'])->name('roles.massDestroy');
Route::resource('roles', Admin\RolesController::class);

// Users
Route::delete('users/destroy', [Admin\UsersController::class, 'massDestroy'])->name('users.massDestroy');
Route::post('users/media', [Admin\UsersController::class, 'storeMedia'])->name('users.storeMedia');
Route::post('users/ckmedia', [Admin\UsersController::class, 'storeCKEditorImages'])->name('users.storeCKEditorImages');
Route::post('users/parse-csv-import', [Admin\UsersController::class, 'parseCsvImport'])->name('users.parseCsvImport');
Route::post('users/process-csv-import', [Admin\UsersController::class, 'processCsvImport'])->name('users.processCsvImport');
Route::resource('users', Admin\UsersController::class);

// Categories
Route::delete('categories/destroy', [Admin\CategoriesController::class, 'massDestroy'])->name('categories.massDestroy');
Route::post('categories/media', [Admin\CategoriesController::class, 'storeMedia'])->name('categories.storeMedia');
Route::post('categories/ckmedia', [Admin\CategoriesController::class, 'storeCKEditorImages'])->name('categories.storeCKEditorImages');
Route::post('categories/parse-csv-import', [Admin\CategoriesController::class, 'parseCsvImport'])->name('categories.parseCsvImport');
Route::post('categories/process-csv-import', [Admin\CategoriesController::class, 'processCsvImport'])->name('categories.processCsvImport');
Route::resource('categories', Admin\CategoriesController::class);

// Articles
Route::delete('articles/destroy', [Admin\ArticleController::class, 'massDestroy'])->name('contents.massDestroy');
Route::post('articles/mass-publish', [Admin\ArticleController::class, 'massPublish'])->name('contents.massPublish');
Route::post('articles/media', [Admin\ArticleController::class, 'storeMedia'])->name('contents.storeMedia');
Route::post('articles/ckmedia', [Admin\ArticleController::class, 'storeCKEditorImages'])->name('contents.storeCKEditorImages');
Route::post('articles/{content}/remove-content-image', [Admin\ArticleController::class, 'removeContentImage'])->name('contents.removeContentImage');
Route::post('articles/parse-csv-import', [Admin\ArticleController::class, 'parseCsvImport'])->name('contents.parseCsvImport');
Route::post('articles/process-csv-import', [Admin\ArticleController::class, 'processCsvImport'])->name('contents.processCsvImport');
Route::resource('articles', Admin\ArticleController::class, ['names' => 'contents', 'parameters' => ['articles' => 'content']]);

// Pages
Route::delete('pages/destroy', [Admin\PageController::class, 'massDestroy'])->name('pages.massDestroy');
Route::post('pages/media', [Admin\PageController::class, 'storeMedia'])->name('pages.storeMedia');
Route::post('pages/ckmedia', [Admin\PageController::class, 'storeCKEditorImages'])->name('pages.storeCKEditorImages');
Route::resource('pages', Admin\PageController::class);

// Media Library
Route::delete('media/destroy', [Admin\MediaLibraryController::class, 'massDestroy'])->name('media.massDestroy');
Route::post('media/media', [Admin\MediaLibraryController::class, 'storeMedia'])->name('media.storeMedia');
Route::post('media/generate', [Admin\MediaLibraryController::class, 'generateAi'])->name('media.generateAi');
Route::post('media/{id}/replace', [Admin\MediaLibraryController::class, 'replace'])->name('media.replace');
Route::post('media/{id}/crop', [Admin\MediaLibraryController::class, 'crop'])->name('media.crop');
Route::post('media/{id}/cache', [Admin\MediaLibraryController::class, 'regenerateCache'])->name('media.regenerateCache');
Route::delete('media/{id}/cache', [Admin\MediaLibraryController::class, 'clearCache'])->name('media.clearCache');
Route::post('media/{id}/meta', [Admin\MediaLibraryController::class, 'updateMeta'])->name('media.updateMeta');
Route::resource('media', Admin\MediaLibraryController::class)->only(['index', 'show', 'store', 'destroy']);

// Form Submissions
Route::delete('form-submissions/destroy', [Admin\FormSubmissionController::class, 'massDestroy'])->name('form-submissions.massDestroy');
Route::resource('form-submissions', Admin\FormSubmissionController::class)->only(['index', 'show', 'destroy']);

// Ideas
Route::delete('ideas/destroy', [Admin\IdeasController::class, 'massDestroy'])->name('ideas.massDestroy');
Route::post('ideas/parse-csv-import', [Admin\IdeasController::class, 'parseCsvImport'])->name('ideas.parseCsvImport');
Route::post('ideas/process-csv-import', [Admin\IdeasController::class, 'processCsvImport'])->name('ideas.processCsvImport');
Route::post('ideas/generate-ai', [Admin\IdeasController::class, 'generateIdeas'])->name('ideas.generateAi');
Route::post('ideas/save-ai', [Admin\IdeasController::class, 'saveIdeas'])->name('ideas.saveAi');
Route::post('ideas/generate-content', [Admin\IdeasController::class, 'generateContent'])->name('ideas.generateContent');
Route::post('ideas/bulk-generate-content', [Admin\IdeasController::class, 'bulkGenerateContent'])->name('ideas.bulkGenerateContent');
Route::resource('ideas', Admin\IdeasController::class);

// Settings (replaces Config CRUD)
Route::get('settings', [Admin\ConfigController::class, 'index'])->name('settings.index');
Route::get('settings/{group}', [Admin\ConfigController::class, 'group'])->name('settings.group')->where('group', 'general|appearance|pwa|customcss|app|gdpr|visibility|mcp');
Route::post('settings/{group}', [Admin\ConfigController::class, 'updateGroup'])->name('settings.updateGroup')->where('group', 'general|appearance|pwa|customcss|app|gdpr|visibility|mcp');
Route::get('settings/mcp/generate-key', [Admin\McpSettingsController::class, 'generateKey'])->name('settings.mcp.generateKey');
Route::post('settings/pwa/upload-icon', [Admin\ConfigController::class, 'uploadIcon'])->name('settings.uploadIcon');
Route::get('settings/appearance/preview/{template}', [Admin\ConfigController::class, 'previewTemplate'])->name('settings.appearance.preview')->where('template', '[a-z\-]+');
Route::post('settings/appearance/install-homepage', [Admin\ConfigController::class, 'installHomepage'])->name('settings.appearance.installHomepage');
Route::post('settings/gdpr/install-privacy-page', [Admin\ConfigController::class, 'installPrivacyPage'])->name('settings.gdpr.installPrivacyPage');

// Backward compat redirect
Route::get('configs', function () {
    return redirect()->route('vela.admin.settings.index');
})->name('configs.index');

// Comments
Route::delete('comments/destroy', [Admin\CommentsController::class, 'massDestroy'])->name('comments.massDestroy');
Route::resource('comments', Admin\CommentsController::class);

// Cache
Route::post('cache/clear', [Admin\CacheController::class, 'clear'])->name('cache.clear');
Route::post('cache/clear-home', [Admin\CacheController::class, 'clearHome'])->name('cache.clear-home');
Route::post('cache/clear-pages', [Admin\CacheController::class, 'clearPages'])->name('cache.clear-pages');
Route::post('cache/clear-articles', [Admin\CacheController::class, 'clearArticles'])->name('cache.clear-articles');
Route::post('cache/clear-images', [Admin\CacheController::class, 'clearImages'])->name('cache.clear-images');
Route::post('cache/clear-pwa', [Admin\CacheController::class, 'clearPwa'])->name('cache.clear-pwa');

// AI Settings
Route::get('ai-settings', [Admin\AiSettingsController::class, 'index'])->name('ai-settings.index');
Route::post('ai-settings', [Admin\AiSettingsController::class, 'update'])->name('ai-settings.update');
Route::get('ai-settings/status', [Admin\AiSettingsController::class, 'status'])->name('ai-settings.status');

// AI Chat
Route::post('ai-chat/message', [Admin\AiChatController::class, 'message'])->name('ai-chat.message');
Route::get('ai-chat/poll/{conversation}', [Admin\AiChatController::class, 'poll'])->name('ai-chat.poll');
Route::post('ai-chat/undo/{actionLog}', [Admin\AiChatController::class, 'undo'])->name('ai-chat.undo');
Route::get('ai-chat/conversations', [Admin\AiChatController::class, 'conversations'])->name('ai-chat.conversations');
Route::get('ai-chat/history/{conversation}', [Admin\AiChatController::class, 'history'])->name('ai-chat.history');

// Tools
Route::prefix('tools')->name('tools.')->group(function () {
    Route::get('/', [Admin\Tools\ToolsController::class, 'index'])->name('index');

    // Google Analytics
    Route::get('google-analytics', [Admin\Tools\GoogleAnalyticsController::class, 'index'])->name('google-analytics');
    Route::post('google-analytics/config', [Admin\Tools\GoogleAnalyticsController::class, 'updateConfig'])->name('google-analytics.config');
    Route::get('google-analytics/reports', [Admin\Tools\GoogleAnalyticsController::class, 'reports'])->name('google-analytics.reports');

    // Google Search Console
    Route::get('search-console', [Admin\Tools\SearchConsoleController::class, 'index'])->name('search-console');
    Route::post('search-console/config', [Admin\Tools\SearchConsoleController::class, 'updateConfig'])->name('search-console.config');
    Route::get('search-console/reports', [Admin\Tools\SearchConsoleController::class, 'reports'])->name('search-console.reports');

    // PageSpeed
    Route::get('pagespeed', [Admin\Tools\PagespeedController::class, 'index'])->name('pagespeed');
    Route::post('pagespeed/scan', [Admin\Tools\PagespeedController::class, 'scan'])->name('pagespeed.scan');
    Route::get('pagespeed/results/{id}', [Admin\Tools\PagespeedController::class, 'show'])->name('pagespeed.show');

    // Email Tester
    Route::get('email-tester', [Admin\Tools\EmailTesterController::class, 'index'])->name('email-tester');
    Route::post('email-tester/send', [Admin\Tools\EmailTesterController::class, 'send'])->name('email-tester.send');

    // W3C Validator
    Route::get('w3c-validator', [Admin\Tools\W3cValidatorController::class, 'index'])->name('w3c-validator');
    Route::post('w3c-validator/validate', [Admin\Tools\W3cValidatorController::class, 'check'])->name('w3c-validator.validate');

    // Cloudflare
    Route::get('cloudflare', [Admin\Tools\CloudflareController::class, 'index'])->name('cloudflare');
    Route::post('cloudflare/config', [Admin\Tools\CloudflareController::class, 'updateConfig'])->name('cloudflare.config');
    Route::post('cloudflare/purge', [Admin\Tools\CloudflareController::class, 'purge'])->name('cloudflare.purge');
    Route::get('cloudflare/status', [Admin\Tools\CloudflareController::class, 'status'])->name('cloudflare.status');

    // Repostra
    Route::get('repostra', [Admin\Tools\RepostraController::class, 'index'])->name('repostra');
    Route::post('repostra/config', [Admin\Tools\RepostraController::class, 'updateConfig'])->name('repostra.config');

    // Reviews
    Route::get('reviews', [Admin\Tools\ReviewsController::class, 'index'])->name('reviews');
    Route::post('reviews', [Admin\Tools\ReviewsController::class, 'store'])->name('reviews.store');
    Route::put('reviews/{id}', [Admin\Tools\ReviewsController::class, 'update'])->name('reviews.update');
    Route::delete('reviews/{id}', [Admin\Tools\ReviewsController::class, 'destroy'])->name('reviews.destroy');
    Route::post('reviews/sync', [Admin\Tools\ReviewsController::class, 'sync'])->name('reviews.sync');
    Route::post('reviews/config', [Admin\Tools\ReviewsController::class, 'updateConfig'])->name('reviews.config');
});
