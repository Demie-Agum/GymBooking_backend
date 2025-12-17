<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class CheckAuthToken
{
    public function handle(Request $request, Closure $next)
    {
        // Check if token is provided
        if (!$request->bearerToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Token not provided'
            ], 401);
        }

        try {
            // Try to find the token in the database
            $token = PersonalAccessToken::findToken($request->bearerToken());
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Invalid token'
                ], 401);
            }

            // Check if token is expired
            if ($token->expires_at && $token->expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Token has expired'
                ], 401);
            }

            // Get the user associated with the token
            $user = $token->tokenable;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - User not found'
                ], 401);
            }

            // Set the authenticated user
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Token validation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Authentication error'
            ], 401);
        }

        return $next($request);
    }
}
