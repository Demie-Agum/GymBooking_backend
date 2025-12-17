<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\GymSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * Create a new booking
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gym_session_id' => 'required|exists:gym_sessions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            
            // Check and auto-downgrade if subscription expired
            $user->checkAndDowngradeIfExpired();
            
            // Reload user to get updated membership level
            $user->refresh();
            $user->load('membershipLevel');
            
            $gymSession = GymSession::findOrFail($request->gym_session_id);

            // Check if session has already passed
            $sessionDate = $gymSession->date;
            $sessionStartTime = $gymSession->start_time;
            
            // Create full datetime for session start
            $sessionDateTime = Carbon::parse($sessionDate->format('Y-m-d') . ' ' . $sessionStartTime);
            
            // Check if session has already passed
            if ($sessionDateTime->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot book a session that has already passed'
                ], 400);
            }

            // Check if user has membership level
            if (!$user->membershipLevel) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must have a membership level to book sessions'
                ], 403);
            }

            // Check if subscription is active
            if (!$user->isSubscriptionActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please renew your membership to book sessions.'
                ], 403);
            }

            // Check if already booked
            $existingBooking = Booking::where('user_id', $user->id)
                ->where('gym_session_id', $gymSession->id)
                ->first();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already booked this session'
                ], 400);
            }

            // Check for overlapping bookings
            $overlappingBookings = Booking::where('user_id', $user->id)
                ->whereHas('gymSession', function($query) use ($gymSession) {
                    $query->where('date', $gymSession->date->format('Y-m-d'));
                })
                ->with('gymSession')
                ->get();

            foreach ($overlappingBookings as $booking) {
                if ($gymSession->overlapsWith($booking->gymSession)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You have an overlapping booking at this time'
                    ], 400);
                }
            }

            // Check weekly limit
            $membershipLevel = $user->membershipLevel;
            if (!$membershipLevel->isUnlimited()) {
                $weekStart = Carbon::parse($gymSession->date)->startOfWeek();
                $weekEnd = Carbon::parse($gymSession->date)->endOfWeek();

                $weeklyBookings = Booking::where('user_id', $user->id)
                    ->where('status', 'confirmed')
                    ->whereHas('gymSession', function($query) use ($weekStart, $weekEnd) {
                        $query->whereBetween('date', [$weekStart, $weekEnd]);
                    })
                    ->count();

                if ($weeklyBookings >= $membershipLevel->weekly_limit) {
                    return response()->json([
                        'success' => false,
                        'message' => "You have reached your weekly booking limit ({$membershipLevel->weekly_limit} bookings per week)"
                    ], 400);
                }
            }

            // Use database transaction with row locking to prevent race conditions
            $booking = DB::transaction(function () use ($user, $gymSession, $membershipLevel) {
                // Lock the session row for update to prevent concurrent bookings
                $lockedSession = GymSession::lockForUpdate()->findOrFail($gymSession->id);
                
                // Check if session is full (count both confirmed and pending bookings as taken spots)
                // Pending bookings reserve spots until admin confirms/rejects them
                $takenSpots = Booking::where('gym_session_id', $lockedSession->id)
                    ->whereIn('status', ['confirmed', 'pending'])
                    ->count();

                $isFull = $takenSpots >= $lockedSession->capacity;

                // All user bookings start as 'pending' - admin must confirm
                // Platinum users (priority = 1) can be queued if full
                $status = 'pending';
                if ($isFull) {
                    if ($membershipLevel->priority === 1) {
                        $status = 'queued';
                    } else {
                        throw new \Exception('This session is full');
                    }
                }

                // Create booking
                return Booking::create([
                    'user_id' => $user->id,
                    'gym_session_id' => $lockedSession->id,
                    'status' => $status,
                ]);
            });

            $message = $booking->status === 'queued' 
                ? 'You have been added to the queue. You will be automatically confirmed if a spot becomes available.'
                : 'Booking created successfully. Waiting for admin confirmation.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'booking' => [
                        'id' => $booking->id,
                        'status' => $booking->status,
                        'gym_session' => [
                            'id' => $gymSession->id,
                            'name' => $gymSession->name,
                            'date' => $gymSession->date->format('Y-m-d'),
                            'start_time' => $gymSession->start_time,
                            'end_time' => $gymSession->end_time,
                        ],
                        'created_at' => $booking->created_at,
                    ]
                ]
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
     * Get user's bookings
     */
    public function myBookings(Request $request)
    {
        try {
            $user = $request->user();

            $bookings = Booking::where('user_id', $user->id)
                ->with('gymSession')
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(function($booking) {
                    // Filter out bookings with missing gym sessions
                    return $booking->gymSession !== null;
                })
                ->map(function($booking) {
                    return [
                        'id' => $booking->id,
                        'status' => $booking->status,
                        'gym_session' => [
                            'id' => $booking->gymSession->id,
                            'name' => $booking->gymSession->name,
                            'date' => $booking->gymSession->date->format('Y-m-d'),
                            'start_time' => $booking->gymSession->start_time,
                            'end_time' => $booking->gymSession->end_time,
                            'capacity' => $booking->gymSession->capacity,
                            'image' => $booking->gymSession->image ? asset('storage/' . $booking->gymSession->image) : null,
                        ],
                        'created_at' => $booking->created_at,
                    ];
                })
                ->values(); // Re-index array after filter

            return response()->json([
                'success' => true,
                'data' => $bookings
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
     * Cancel a booking
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $booking = Booking::where('user_id', $user->id)
                ->findOrFail($id);

            $gymSession = $booking->gymSession;

            // Check if session has already passed
            $sessionDateTime = Carbon::parse($gymSession->date->format('Y-m-d') . ' ' . $gymSession->start_time);
            if ($sessionDateTime->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel a booking for a session that has already passed'
                ], 400);
            }

            $booking->delete();

            // If there are queued bookings, promote the first one
            if ($booking->status === 'confirmed') {
                $queuedBooking = Booking::where('gym_session_id', $gymSession->id)
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
}



