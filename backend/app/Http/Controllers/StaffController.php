<?php

namespace App\Http\Controllers;

use App\Models\GymSession;
use App\Models\Booking;
use App\Models\User;
use App\Models\MembershipLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StaffController extends Controller
{
    /**
     * Get all bookings (Staff can view)
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
     * Get all gym sessions (Staff can view)
     */
    public function getAllSessions(Request $request)
    {
        try {
            $query = GymSession::withCount(['bookings as confirmed_count' => function($q) {
                $q->where('status', 'confirmed');
            }]);

            // Filter by date
            if ($request->has('date')) {
                $query->whereDate('date', $request->date);
            }

            // Filter future sessions only
            if ($request->has('future_only') && $request->future_only) {
                $query->where('date', '>=', now()->toDateString());
            }

            $sessions = $query->orderBy('date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $sessions->map(function($session) {
                    return [
                        'id' => $session->id,
                        'name' => $session->name,
                        'date' => $session->date->format('Y-m-d'),
                        'start_time' => $session->start_time,
                        'end_time' => $session->end_time,
                        'capacity' => $session->capacity,
                        'image' => $session->image ? asset('storage/' . $session->image) : null,
                        'confirmed_count' => $session->confirmed_count ?? 0,
                        'available_spots' => max(0, $session->capacity - ($session->confirmed_count ?? 0)),
                        'is_full' => ($session->confirmed_count ?? 0) >= $session->capacity,
                        'created_at' => $session->created_at,
                    ];
                })
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch gym sessions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Staff can create bookings for users (like admin)
     */
    public function createBookingForUser(Request $request)
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
     * Update booking status (Staff can manage like admin)
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
     * Delete booking (Staff can manage like admin)
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
     * Staff can cancel bookings (limited access)
     */
    public function cancelBooking($id)
    {
        try {
            $booking = Booking::with('gymSession')->findOrFail($id);
            $booking->delete();

            // If there are queued bookings, promote the first one
            if ($booking->status === 'confirmed') {
                $queuedBooking = Booking::where('gym_session_id', $booking->gym_session_id)
                    ->where('status', 'queued')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($queuedBooking) {
                    $queuedBooking->update(['status' => 'confirmed']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users (members only) for booking creation
     */
    public function getUsers(Request $request)
    {
        try {
            $query = User::with('membershipLevel')->where('role', 'user');

            // Search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('firstname', 'like', "%{$search}%")
                      ->orWhere('lastname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
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
     * Create a new user (Staff can only create users with role 'user')
     */
    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'middlename' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
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

        try {
            // Calculate subscription expiry if membership level is provided and expiry not set
            $subscriptionExpiresAt = $request->subscription_expires_at;
            if ($request->membership_level_id && !$subscriptionExpiresAt) {
                $membershipLevel = MembershipLevel::find($request->membership_level_id);
                if ($membershipLevel && $membershipLevel->default_duration_days) {
                    $subscriptionExpiresAt = now()->addDays($membershipLevel->default_duration_days);
                }
            }

            // Staff can only create users with role 'user'
            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'middlename' => $request->middlename,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'user', // Always 'user' for staff-created accounts
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
     * Get single user details (Staff can view)
     */
    public function getUser($id)
    {
        try {
            $user = User::with('membershipLevel')->where('role', 'user')->findOrFail($id);

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
                    'membership_level' => $user->membershipLevel ? [
                        'id' => $user->membershipLevel->id,
                        'name' => $user->membershipLevel->name,
                        'weekly_limit' => $user->membershipLevel->weekly_limit,
                        'priority' => $user->membershipLevel->priority,
                    ] : null,
                    'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                    'is_subscription_active' => $user->isSubscriptionActive(),
                    'is_subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                    'days_until_expiry' => $user->daysUntilExpiry(),
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
     * Update user (Staff can only update users with role 'user')
     */
    public function updateUser(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'sometimes|string|max:255',
            'lastname' => 'sometimes|string|max:255',
            'middlename' => 'sometimes|string|max:255|nullable',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6',
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
            // Staff can only update users with role 'user'
            $user = User::where('role', 'user')->findOrFail($id);
            
            $updateData = $request->only([
                'firstname', 'lastname', 'middlename', 'email', 
                'is_verified', 'profile_picture'
            ]);

            // Handle membership level and subscription expiry
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
     * Update user subscription expiry and membership level (Staff can manage)
     */
    public function updateUserSubscriptionExpiry(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'subscription_expires_at' => 'nullable|date|after:today',
            'membership_level_id' => 'sometimes|exists:membership_levels,id|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('role', 'user')->findOrFail($userId);
            
            $updateData = [];

            // Handle membership level update
            if ($request->has('membership_level_id')) {
                $updateData['membership_level_id'] = $request->membership_level_id;
            }

            // Handle subscription expiry date
            if ($request->has('subscription_expires_at')) {
                $updateData['subscription_expires_at'] = $request->subscription_expires_at ?: null;
            } elseif ($request->has('membership_level_id') && !$request->has('subscription_expires_at')) {
                // If membership level changed and no expiry provided, recalculate
                $membershipLevel = MembershipLevel::find($request->membership_level_id);
                if ($membershipLevel && $membershipLevel->default_duration_days) {
                    $updateData['subscription_expires_at'] = now()->addDays($membershipLevel->default_duration_days);
                } elseif (!$membershipLevel || $membershipLevel->name === 'Free') {
                    $updateData['subscription_expires_at'] = null;
                }
            }

            $user->update($updateData);
            $user->load('membershipLevel');

            return response()->json([
                'success' => true,
                'message' => 'Subscription updated successfully',
                'data' => [
                    'id' => $user->id,
                    'membership_level' => $user->membershipLevel ? [
                        'id' => $user->membershipLevel->id,
                        'name' => $user->membershipLevel->name,
                    ] : null,
                    'subscription_expires_at' => $user->subscription_expires_at ? $user->subscription_expires_at->format('Y-m-d H:i:s') : null,
                    'subscription_status' => $user->isSubscriptionActive() ? 'active' : 'expired',
                    'subscription_expiring_soon' => $user->isSubscriptionExpiringSoon(),
                    'days_until_expiry' => $user->daysUntilExpiry(),
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics (Staff limited view)
     */
    public function getDashboardStats()
    {
        try {
            $stats = [
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

    /**
     * Delete user (Staff can only delete users with role 'user')
     */
    public function deleteUser($id)
    {
        try {
            // Staff can only delete users with role 'user'
            $user = User::with('membershipLevel')->where('role', 'user')->findOrFail($id);
            
            // Prevent deleting yourself (if staff somehow has user role, which shouldn't happen)
            if ($user->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 400);
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
}
