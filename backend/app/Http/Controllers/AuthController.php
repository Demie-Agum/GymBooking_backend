<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\EmailVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Register a new user (with email verification)
     */
    public function register(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Generate OTP and verification token
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $verificationToken = Str::random(64);

            // Get default membership level (Free = ID 1)
            $defaultMembershipLevel = \App\Models\MembershipLevel::where('name', 'Free')->first();

            // Create user (unverified)
            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'middlename' => $request->middlename,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'otp_code' => $otp,
                'otp_expires_at' => Carbon::now()->addMinutes(15),
                'verification_token' => $verificationToken,
                'is_verified' => false,
                'membership_level_id' => $defaultMembershipLevel ? $defaultMembershipLevel->id : null,
            ]);

            // Generate verification URLs
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5500');
            $verificationUrl = $frontendUrl . '/verify.html?token=' . $verificationToken;
            // Generate OTP link for easy access if tab is closed
            $otpLink = $frontendUrl . '/verify.html?otp=' . $otp . '&email=' . urlencode($user->email);
 
            // Send verification email with OTP link
            $user->notify(new EmailVerification($otp, $user->email, $otpLink));

            return response()->json([
                'success' => true,
                'message' => 'Registration successful. Please check your email to verify your account.',
                'data' => [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'requires_verification' => true
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify email with OTP
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already verified'
                ], 400);
            }

            // Check if OTP is expired
            if (Carbon::now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTP has expired. Please request a new one.'
                ], 400);
            }

            // Verify OTP
            if ($user->otp_code !== $request->otp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP code'
                ], 400);
            }

            // Mark as verified
            $user->update([
                'is_verified' => true,
                'email_verified_at' => Carbon::now(),
                'otp_code' => null,
                'otp_expires_at' => null,
                'verification_token' => null,
            ]);

            // FIXED: Delete all old tokens before creating new one
            $user->tokens()->delete();
            
            // Create new token
            $token = $user->createToken('auth_token')->plainTextToken;

            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'middlename' => $user->middlename,
                        'email' => $user->email,
                        'is_verified' => $user->is_verified,
                        'membership_level' => $user->membershipLevel ? [
                            'id' => $user->membershipLevel->id,
                            'name' => $user->membershipLevel->name,
                            'weekly_limit' => $user->membershipLevel->weekly_limit,
                            'priority' => $user->membershipLevel->priority,
                        ] : null,
                        'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                        'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                        'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                        'days_until_expiry' => $user->daysUntilExpiry(),
                        'created_at' => $user->created_at,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify email with token (link)
     */
    public function verifyToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('verification_token', $request->token)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification token'
                ], 404);
            }

            if ($user->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already verified'
                ], 400);
            }

            // Check if OTP/token is expired
            if (Carbon::now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verification link has expired. Please request a new one.'
                ], 400);
            }

            // Mark as verified
            $user->update([
                'is_verified' => true,
                'email_verified_at' => Carbon::now(),
                'otp_code' => null,
                'otp_expires_at' => null,
                'verification_token' => null,
            ]);

            // FIXED: Delete all old tokens before creating new one
            $user->tokens()->delete();
            
            // Create new token
            $token = $user->createToken('auth_token')->plainTextToken;

            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'middlename' => $user->middlename,
                        'email' => $user->email,
                        'is_verified' => $user->is_verified,
                        'membership_level' => $user->membershipLevel ? [
                            'id' => $user->membershipLevel->id,
                            'name' => $user->membershipLevel->name,
                            'weekly_limit' => $user->membershipLevel->weekly_limit,
                            'priority' => $user->membershipLevel->priority,
                        ] : null,
                        'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                        'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                        'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                        'days_until_expiry' => $user->daysUntilExpiry(),
                        'created_at' => $user->created_at,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($user->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already verified'
                ], 400);
            }

            // Generate new OTP and token
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $verificationToken = Str::random(64);

            $user->update([
                'otp_code' => $otp,
                'otp_expires_at' => Carbon::now()->addMinutes(15),
                'verification_token' => $verificationToken,
            ]);

            // Generate verification URLs
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5500');
            $verificationUrl = $frontendUrl . '/verify.html?token=' . $verificationToken;
            // Generate OTP link for easy access if tab is closed
            $otpLink = $frontendUrl . '/verify.html?otp=' . $otp . '&email=' . urlencode($user->email);

            // Send verification email with OTP link
            $user->notify(new EmailVerification($otp, $user->email, $otpLink));

            return response()->json([
                'success' => true,
                'message' => 'Verification code sent successfully',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend verification code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user (only if verified)
     * FIXED: Revokes all old tokens before creating new one
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Check if email is verified
            if (!$user->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify your email before logging in',
                    'requires_verification' => true,
                    'email' => $user->email
                ], 403);
            }

            // FIXED: Delete ALL old tokens before creating a new one
            // This ensures only ONE active session per user
            $user->tokens()->delete();
            
            // Create new token with device name for tracking
            $deviceName = $request->header('User-Agent') ?? 'unknown-device';
            $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'middlename' => $user->middlename,
                        'email' => $user->email,
                        'role' => $user->role ?? 'user',
                        'is_verified' => $user->is_verified,
                        'membership_level' => $user->membershipLevel ? [
                            'id' => $user->membershipLevel->id,
                            'name' => $user->membershipLevel->name,
                            'weekly_limit' => $user->membershipLevel->weekly_limit,
                            'priority' => $user->membershipLevel->priority,
                        ] : null,
                        'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                        'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                        'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                        'days_until_expiry' => $user->daysUntilExpiry(),
                        'created_at' => $user->created_at,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user (revoke current token)
     * FIXED: Properly revokes the current access token
     */
    public function logout(Request $request)
    {
        try {
            // Delete only the current access token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout from all devices (revoke ALL tokens)
     * NEW: Added this method for logging out from all sessions
     */
    public function logoutAll(Request $request)
    {
        try {
            // Delete ALL tokens for this user
            $request->user()->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out from all devices successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        try {
            $user = $request->user();
            
            // Check and auto-downgrade if subscription expired
            $user->checkAndDowngradeIfExpired();
            
            // Reload user to get updated membership level
            $user->refresh();
            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'middlename' => $user->middlename,
                        'email' => $user->email,
                        'role' => $user->role ?? 'user',
                        'is_verified' => $user->is_verified,
                        'membership_level' => $user->membershipLevel ? [
                            'id' => $user->membershipLevel->id,
                            'name' => $user->membershipLevel->name,
                            'weekly_limit' => $user->membershipLevel->weekly_limit,
                            'priority' => $user->membershipLevel->priority,
                        ] : null,
                        'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                        'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                        'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                        'days_until_expiry' => $user->daysUntilExpiry(),
                        'created_at' => $user->created_at,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh token
     * FIXED: Properly deletes old token before creating new one
     */
    public function refresh(Request $request)
    {
        try {
            // Delete the current token
            $request->user()->currentAccessToken()->delete();
            
            // Create a new token
            $token = $request->user()->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}