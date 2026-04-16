<?php

use VelaBuild\Core\Http\Controllers\Auth;
use Illuminate\Support\Facades\Route;

// Login
Route::get('login', [Auth\LoginController::class, 'showLoginForm'])->name('login');
Route::post('login', [Auth\LoginController::class, 'login'])->name('login.submit');
Route::post('logout', [Auth\LoginController::class, 'logout'])->name('logout');

// Register
Route::get('register', [Auth\RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('register', [Auth\RegisterController::class, 'register'])->name('register.submit');

// Password Reset
Route::get('password/reset', [Auth\ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
Route::post('password/email', [Auth\ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
Route::get('password/reset/{token}', [Auth\ResetPasswordController::class, 'showResetForm'])->name('password.reset');
Route::post('password/reset', [Auth\ResetPasswordController::class, 'reset'])->name('password.update');

// Email Verification
Route::get('email/verify', [Auth\VerificationController::class, 'show'])->name('verification.notice');
Route::get('email/verify/{id}/{hash}', [Auth\VerificationController::class, 'verify'])->name('verification.verify');
Route::post('email/resend', [Auth\VerificationController::class, 'resend'])->name('verification.resend');

// Two-Factor (requires vela auth)
Route::middleware(config('vela.middleware.admin', ['web', 'vela.auth', 'vela.2fa', 'vela.gates']))->group(function () {
    Route::get('two-factor', [Auth\TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('two-factor', [Auth\TwoFactorController::class, 'check'])->name('two-factor.check');
    Route::get('two-factor/resend', [Auth\TwoFactorController::class, 'resend'])->name('two-factor.resend');
});

// Profile (requires vela auth)
Route::middleware(config('vela.middleware.admin', ['web', 'vela.auth', 'vela.2fa', 'vela.gates']))->prefix('profile')->as('profile.')->group(function () {
    Route::get('password', [Auth\ChangePasswordController::class, 'edit'])->name('password.edit');
    Route::post('password', [Auth\ChangePasswordController::class, 'update'])->name('password.update');
    Route::post('profile', [Auth\ChangePasswordController::class, 'updateProfile'])->name('password.updateProfile');
    Route::post('profile/media', [Auth\ChangePasswordController::class, 'storeMedia'])->name('password.storeMedia');
    Route::post('profile/destroy', [Auth\ChangePasswordController::class, 'destroy'])->name('password.destroyProfile');
    Route::post('profile/two-factor', [Auth\ChangePasswordController::class, 'toggleTwoFactor'])->name('password.toggleTwoFactor');
});
