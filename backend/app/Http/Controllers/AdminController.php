<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GymSession;
use App\Models\Booking;
use App\Models\MembershipLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Create a new user (Admin only)
     */
    public function createUser(Request $request)
    {
        $currentUser = auth()->user();
        
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:super_admin,admin,staff,user',
            'membership_level_id' => 'nullable|exists:membership_levels,id',
            'subscription_expires_at' => 'nullable|date|after:today',
            'is_verified' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Only Super Admin can create Admin or Super Admin accounts
        if (in_array($request->role, ['admin', 'super_admin'])) {
            if (!$currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Super Admin can create Admin or Super Admin accounts'
                ], 403);
            }
        }

        // Only Super Admin can create Super Admin accounts
        if ($request->role === 'super_admin' && !$currentUser->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only Super Admin can create Super Admin accounts'
            ], 403);
        }

        try {
            // Calculate subscription expiry if membership level is provided and expiry not set
            $subscriptionExpiresAt = $request->subscription_expires_at;
            if ($request->membership_level_id && !$subscriptionExpiresAt) {
                $membershipLevel = MembershipLevel::find($request->membership_level_id);
                if ($membershipLevel && $membershipLevel->default_duration_days) {
                    $subscriptionExpiresAt = now()->addDays($membershipLevel->default_duration_days);
                }
            }

            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'middlename' => $request->middlename,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'membership_level_id' => $request->membership_level_id,
                'subscription_expires_at' => $subscriptionExpiresAt,
                'is_verified' => $request->is_verified ?? true,
                'email_verified_at' => $request->is_verified ? now() : null,
            ]);

            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users (Admin only)
     */
    public function getUsers(Request $request)
    {
        try {
            $query = User::with('membershipLevel');

            // Search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('firstname', 'like', "%{$search}%")
                      ->orWhere('lastname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Role filter
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            $users = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $users->map(function($user) {
                    return [
                        'id' => $user->id,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'middlename' => $user->middlename,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_verified' => $user->is_verified,
                        'profile_picture' => $user->profile_picture,
                        'membership_level' => $user->membershipLevel ? [
                            'id' => $user->membershipLevel->id,
                            'name' => $user->membershipLevel->name,
                        ] : null,
                        'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                        'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                        'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                        'days_until_expiry' => $user->daysUntilExpiry(),
                        'created_at' => $user->created_at,
                    ];
                })
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single user (Admin only)
     */
    public function getUser($id)
    {
        try {
            $user = User::with('membershipLevel', 'bookings')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'middlename' => $user->middlename,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_verified' => $user->is_verified,
                    'profile_picture' => $user->profile_picture,
                    'membership_level' => $user->membershipLevel,
                    'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                    'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                    'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                    'days_until_expiry' => $user->daysUntilExpiry(),
                    'bookings_count' => $user->bookings->count(),
                    'created_at' => $user->created_at,
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
     * Update user (Admin only)
     */
    public function updateUser(Request $request, $id)
    {
        $currentUser = auth()->user();
        
        $validator = Validator::make($request->all(), [
            'firstname' => 'sometimes|string|max:255',
            'lastname' => 'sometimes|string|max:255',
            'middlename' => 'sometimes|string|max:255|nullable',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|in:super_admin,admin,staff,user',
            'membership_level_id' => 'sometimes|exists:membership_levels,id|nullable',
            'subscription_expires_at' => 'sometimes|nullable|date|after:today',
            'is_verified' => 'sometimes|boolean',
            'profile_picture' => 'sometimes|nullable|string', // Base64 image
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::findOrFail($id);
            
            // Super Admin role cannot be changed
            if ($user->isSuperAdmin() && $request->has('role') && $request->role !== 'super_admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Super Admin role cannot be changed'
                ], 403);
            }
            
            // Only Super Admin can change roles to/from Admin or Super Admin
            if ($request->has('role')) {
                $newRole = $request->role;
                $oldRole = $user->role;
                
                // If changing to/from admin or super_admin
                if (in_array($newRole, ['admin', 'super_admin']) || in_array($oldRole, ['admin', 'super_admin'])) {
                    if (!$currentUser->isSuperAdmin()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Only Super Admin can change roles to/from Admin or Super Admin'
                        ], 403);
                    }
                }
                
                // Only Super Admin can create Super Admin
                if ($newRole === 'super_admin' && !$currentUser->isSuperAdmin()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only Super Admin can assign Super Admin role'
                    ], 403);
                }
            }
            
            $updateData = $request->only([
                'firstname', 'lastname', 'middlename', 'email', 
                'is_verified', 'profile_picture'
            ]);
            
            // Only include role if it's allowed
            if ($request->has('role')) {
                $updateData['role'] = $request->role;
            }
            
            // Only include membership_level_id if it's for a user
            if ($request->has('membership_level_id')) {
                $updateData['membership_level_id'] = $request->membership_level_id;
            }

            // Handle subscription expiry date
            if ($request->has('subscription_expires_at')) {
                // If explicitly provided (even if null/empty), use it
                $updateData['subscription_expires_at'] = $request->subscription_expires_at ?: null;
            } elseif ($request->has('membership_level_id')) {
                // If membership level is being updated and no expiry date provided, calculate from default
                $membershipLevel = MembershipLevel::find($request->membership_level_id);
                if ($membershipLevel && $membershipLevel->default_duration_days) {
                    $updateData['subscription_expires_at'] = now()->addDays($membershipLevel->default_duration_days);
                } elseif (!$membershipLevel || $membershipLevel->name === 'Free') {
                    // If removing membership or setting to Free, remove expiry
                    $updateData['subscription_expires_at'] = null;
                }
            }

            // Only update password if provided
            if ($request->has('password') && $request->password) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);
            
            // Refresh to get latest data
            $user->refresh();
            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user (Admin/Super Admin only)
     */
    public function deleteUser($id)
    {
        try {
            $currentUser = auth()->user();
            $user = User::with('membershipLevel')->findOrFail($id);
            
            // Super Admin CANNOT be deleted by anyone (including themselves)
            if ($user->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Super Admin accounts cannot be deleted'
                ], 403);
            }
            
            // Prevent deleting yourself
            if ($user->id === $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 400);
            }

            // Only Super Admin can delete Admin accounts
            if ($user->isAdmin() && !$currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Super Admin can delete Admin accounts'
                ], 403);
            }

            // Check if user has active paid membership (not Free)
            if ($user->membershipLevel && $user->membershipLevel->name !== 'Free') {
                // Check if subscription is active (not expired)
                if ($user->isSubscriptionActive()) {
                    $expiryDate = $user->subscription_expires_at 
                        ? $user->subscription_expires_at->format('Y-m-d')
                        : 'No expiry date';
                    
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot delete user. User has an active {$user->membershipLevel->name} membership. Subscription expires on: {$expiryDate}."
                    ], 400);
                }
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all bookings (Admin only)
     */
    public function getAllBookings(Request $request)
    {
        try {
            $query = Booking::with(['user', 'gymSession']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by session
            if ($request->has('gym_session_id')) {
                $query->where('gym_session_id', $request->gym_session_id);
            }

            $bookings = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $bookings->map(function($booking) {
                    return [
                        'id' => $booking->id,
                        'status' => $booking->status,
                        'user' => [
                            'id' => $booking->user->id,
                            'name' => $booking->user->full_name,
                            'email' => $booking->user->email,
                        ],
                        'gym_session' => [
                            'id' => $booking->gymSession->id,
                            'name' => $booking->gymSession->name,
                            'date' => $booking->gymSession->date->format('Y-m-d'),
                            'start_time' => $booking->gymSession->start_time,
                            'end_time' => $booking->gymSession->end_time,
                            'image' => $booking->gymSession->image ? asset('storage/' . $booking->gymSession->image) : null,
                        ],
                        'created_at' => $booking->created_at,
                    ];
                })
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a booking for a user (Admin only)
     */
    public function createBooking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'gym_session_id' => 'required|exists:gym_sessions,id',
            'status' => 'sometimes|in:pending,confirmed,queued,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if already booked
            $existingBooking = Booking::where('user_id', $request->user_id)
                ->where('gym_session_id', $request->gym_session_id)
                ->first();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has already booked this session'
                ], 400);
            }

            // Check if session is full (only if status is confirmed)
            $gymSession = GymSession::findOrFail($request->gym_session_id);
            
            // Check if session has already passed
            $sessionDate = $gymSession->date;
            $sessionStartTime = $gymSession->start_time;
            $sessionDateTime = Carbon::parse($sessionDate->format('Y-m-d') . ' ' . $sessionStartTime);
            
            if ($sessionDateTime->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot book a session that has already passed'
                ], 400);
            }
            
            $status = $request->status ?? 'confirmed';
            
            // Use database transaction with row locking to prevent race conditions
            $booking = DB::transaction(function () use ($request, $gymSession, $status) {
                // Lock the session row for update to prevent concurrent bookings
                $lockedSession = GymSession::lockForUpdate()->findOrFail($gymSession->id);
                
                if ($status === 'confirmed') {
                    // Count both confirmed and pending bookings as taken spots
                    $takenSpots = Booking::where('gym_session_id', $lockedSession->id)
                        ->whereIn('status', ['confirmed', 'pending'])
                        ->count();

                    if ($takenSpots >= $lockedSession->capacity) {
                        throw new \Exception('This session is full');
                    }
                }

                // Create booking
                return Booking::create([
                    'user_id' => $request->user_id,
                    'gym_session_id' => $lockedSession->id,
                    'status' => $status,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully',
                'data' => $booking->load(['user', 'gymSession'])
            ], 201);
        } catch (\Exception $e) {
            // Handle "session full" error with 400 status
            if ($e->getMessage() === 'This session is full') {
                return response()->json([
                    'success' => false,
                    'message' => 'This session is full'
                ], 400);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update booking status (Admin only)
     */
    public function updateBooking(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,cancelled,queued',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = Booking::with('gymSession')->findOrFail($id);
            
            // Validate if trying to confirm a booking when session is already full
            if ($request->status === 'confirmed' && $booking->status !== 'confirmed') {
                $gymSession = $booking->gymSession;
                
                // Count confirmed and pending bookings (excluding current booking)
                $takenSpots = Booking::where('gym_session_id', $gymSession->id)
                    ->where('id', '!=', $booking->id)
                    ->whereIn('status', ['confirmed', 'pending'])
                    ->count();
                
                if ($takenSpots >= $gymSession->capacity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot confirm booking. Session is already full.'
                    ], 400);
                }
            }
            
            $booking->update(['status' => $request->status]);

            return response()->json([
                'success' => true,
                'message' => 'Booking updated successfully',
                'data' => $booking
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete booking (Admin only)
     */
    public function deleteBooking($id)
    {
        try {
            $booking = Booking::findOrFail($id);
            $booking->delete();

            return response()->json([
                'success' => true,
                'message' => 'Booking deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics (Admin only)
     */
    public function getDashboardStats()
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'total_staff' => User::where('role', 'staff')->count(),
                'total_admins' => User::where('role', 'admin')->count(),
                'total_bookings' => Booking::count(),
                'confirmed_bookings' => Booking::where('status', 'confirmed')->count(),
                'queued_bookings' => Booking::where('status', 'queued')->count(),
                'total_sessions' => GymSession::count(),
                'upcoming_sessions' => GymSession::where('date', '>=', now()->toDateString())->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
