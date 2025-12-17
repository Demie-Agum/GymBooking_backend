<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset link via email
     */
    public function sendResetLinkEmail(Request $request)
    {
        // Validate email
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Email address not found in our records.',
                'errors' => $validator->errors()
            ], 404);
        }

        $email = $request->email;
        
        // Find user
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        try {
            // Generate reset token
            $token = Str::random(64);

            // Delete old tokens for this email
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Store new token
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => Carbon::now()
            ]);

            // Generate reset URL pointing to your frontend
            $frontendUrl = rtrim(env('FRONTEND_URL', 'https://demie-agum.github.io/GymBooking/frontend'), '/');
            $resetUrl = $frontendUrl . '/forgotpassword.html?token=' . $token . '&email=' . urlencode($email);

            // Log the reset URL for debugging (remove in production if needed)
            \Log::info('Password reset URL generated: ' . $resetUrl);
            \Log::info('FRONTEND_URL from env: ' . env('FRONTEND_URL'));

            // Send email
            Mail::send('emails.password-reset', [
                'user' => $user,
                'resetUrl' => $resetUrl,
                'token' => $token
            ], function ($message) use ($email, $user) {
                $message->to($email)
                    ->subject('Password Reset Request - Gym Booking System');
            });

            return response()->json([
                'success' => true,
                'message' => 'Password reset link has been sent to your email address.',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Password reset email error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email. Please try again later.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Reset the password
     */
    public function reset(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'token' => 'required|string',
                'password' => 'required|string|min:8',
                'password_confirmation' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if passwords match
            if ($request->password !== $request->password_confirmation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Passwords do not match.'
                ], 422);
            }

            // Check if user exists
            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found with this email address.'
                ], 404);
            }

            // Find the token record
            $tokenRecord = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$tokenRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token. Please request a new password reset link.'
                ], 400);
            }

            // Verify token matches
            if (!Hash::check($request->token, $tokenRecord->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset token. Please request a new password reset link.'
                ], 400);
            }

            // Check if token is expired (24 hours)
            $createdAt = Carbon::parse($tokenRecord->created_at);
            if ($createdAt->addHours(24)->isPast()) {
                // Delete expired token
                DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Reset token has expired. Please request a new password reset link.'
                ], 400);
            }

            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Delete the used token
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Your password has been reset successfully. You can now log in with your new password.'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Password reset error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while resetting your password. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}