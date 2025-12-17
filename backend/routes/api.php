<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\MembershipLevelController;
use App\Http\Controllers\GymSessionController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\ContactController;


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

// Public routes (no authentication required)
Route::get('/membership-levels', [MembershipLevelController::class, 'index']);
Route::get('/sessions', [GymSessionController::class, 'index']);
Route::get('/sessions/{id}', [GymSessionController::class, 'show']);

// Protected routes (require authentication with Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']); // Logout from current device
    Route::post('/logout-all', [AuthController::class, 'logoutAll']); // Logout from all devices
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    
    // Profile routes
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    
    // Booking routes
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/me', [BookingController::class, 'myBookings']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);
    
    // Contact routes
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::get('/contacts/{id}', [ContactController::class, 'show']);
    Route::put('/contacts/{id}', [ContactController::class, 'update']);
    Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);

    // Staff routes (Staff and Admin can access)
    Route::middleware('staff')->group(function () {
        Route::get('/staff/bookings', [StaffController::class, 'getAllBookings']);
        Route::get('/staff/sessions', [StaffController::class, 'getAllSessions']);
        
        // User management (Staff can manage users with role 'user' only)
        Route::get('/staff/users', [StaffController::class, 'getUsers']);
        Route::post('/staff/users', [StaffController::class, 'createUser']);
        Route::get('/staff/users/{id}', [StaffController::class, 'getUser']);
        Route::put('/staff/users/{id}', [StaffController::class, 'updateUser']);
        Route::delete('/staff/users/{id}', [StaffController::class, 'deleteUser']);
        Route::put('/staff/users/{userId}/subscription', [StaffController::class, 'updateUserSubscriptionExpiry']);
        
        Route::post('/staff/bookings', [StaffController::class, 'createBookingForUser']);
        Route::put('/staff/bookings/{id}', [StaffController::class, 'updateBooking']);
        Route::delete('/staff/bookings/{id}', [StaffController::class, 'deleteBooking']);
        Route::get('/staff/dashboard/stats', [StaffController::class, 'getDashboardStats']);
        
        // Session management (Staff can manage sessions like admin)
        Route::post('/staff/sessions', [GymSessionController::class, 'store']);
        Route::put('/staff/sessions/{id}', [GymSessionController::class, 'update']);
        Route::delete('/staff/sessions/{id}', [GymSessionController::class, 'destroy']);
    });

    // Admin routes (Admin only)
    Route::middleware('admin')->group(function () {
        // User management
        Route::post('/admin/users', [AdminController::class, 'createUser']);
        Route::get('/admin/users', [AdminController::class, 'getUsers']);
        Route::get('/admin/users/{id}', [AdminController::class, 'getUser']);
        Route::put('/admin/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);

        // Booking management
        Route::get('/admin/bookings', [AdminController::class, 'getAllBookings']);
        Route::post('/admin/bookings', [AdminController::class, 'createBooking']);
        Route::put('/admin/bookings/{id}', [AdminController::class, 'updateBooking']);
        Route::delete('/admin/bookings/{id}', [AdminController::class, 'deleteBooking']);

        // Session management (already in GymSessionController, but ensure admin access)
        Route::post('/admin/sessions', [GymSessionController::class, 'store']);
        Route::put('/admin/sessions/{id}', [GymSessionController::class, 'update']);
        Route::delete('/admin/sessions/{id}', [GymSessionController::class, 'destroy']);

        // Dashboard stats
        Route::get('/admin/dashboard/stats', [AdminController::class, 'getDashboardStats']);
    });
});