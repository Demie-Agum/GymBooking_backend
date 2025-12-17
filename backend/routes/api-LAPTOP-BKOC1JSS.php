<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ForgotPasswordController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes (no authentication required)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Email verification routes
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/verify-token', [AuthController::class, 'verifyToken']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);

// Password reset routes (MUST be public - users aren't authenticated when resetting password)
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('/reset-password', [ForgotPasswordController::class, 'reset']);

// Protected routes (require authentication with custom middleware)
Route::middleware('check.auth.token')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']); // Logout from current device
    Route::post('/logout-all', [AuthController::class, 'logoutAll']); // Logout from all devices
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    
    // Profile routes
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    
    // Contact routes (CRUD)
    Route::apiResource('contacts', controller: ContactController::class);
});