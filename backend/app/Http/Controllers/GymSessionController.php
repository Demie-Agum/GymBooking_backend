<?php

namespace App\Http\Controllers;

use App\Models\GymSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class GymSessionController extends Controller
{
    /**
     * Get all gym sessions (public - for listing)
     */
    public function index(Request $request)
    {
        try {
            $query = GymSession::withCount(['bookings as confirmed_count' => function($q) {
                $q->where('status', 'confirmed');
            }]);

            // Filter by date if provided
            if ($request->has('date')) {
                $query->whereDate('date', $request->date);
            }

            // Filter future sessions only
            if ($request->has('future_only') && $request->future_only) {
                $query->where(function($q) {
                    $q->whereDate('date', '>', Carbon::today())
                      ->orWhere(function($q2) {
                          $q2->whereDate('date', '=', Carbon::today())
                             ->whereTime('start_time', '>', Carbon::now()->format('H:i:s'));
                      });
                });
            }

            $sessions = $query->orderBy('date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get()
                ->map(function($session) {
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
                });

            return response()->json([
                'success' => true,
                'data' => $sessions
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
     * Get a single gym session
     */
    public function show($id)
    {
        try {
            $session = GymSession::withCount(['bookings as confirmed_count' => function($q) {
                $q->where('status', 'confirmed');
            }])->findOrFail($id);

            $data = [
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

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch gym session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new gym session (Admin only)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'capacity' => 'required|integer|min:1',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        // Custom validation for end_time after start_time and past date/time check
        $validator->after(function ($validator) use ($request) {
            if ($request->has('start_time') && $request->has('end_time')) {
                $startTime = Carbon::createFromFormat('H:i', $request->start_time);
                $endTime = Carbon::createFromFormat('H:i', $request->end_time);
                
                if ($endTime->lte($startTime)) {
                    $validator->errors()->add('end_time', 'The end time must be after the start time.');
                }
            }
            
            // Check if session date/time is in the past
            if ($request->has('date') && $request->has('start_time')) {
                $sessionDate = Carbon::parse($request->date);
                $sessionStartTime = Carbon::createFromFormat('H:i', $request->start_time);
                $sessionDateTime = Carbon::parse($sessionDate->format('Y-m-d') . ' ' . $sessionStartTime->format('H:i:s'));
                
                if ($sessionDateTime->isPast()) {
                    $validator->errors()->add('date', 'Cannot create a session with a past date and time.');
                }
            }
            
            // Check for overlapping sessions with same name and date
            if ($request->has('name') && $request->has('date') && $request->has('start_time') && $request->has('end_time')) {
                $existingSessions = GymSession::where('name', $request->name)
                    ->whereDate('date', $request->date)
                    ->get();
                
                // Create a temporary session object to check overlaps
                $newSession = new GymSession([
                    'name' => $request->name,
                    'date' => $request->date,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                ]);
                
                foreach ($existingSessions as $existing) {
                    if ($newSession->overlapsWith($existing)) {
                        $validator->errors()->add('name', 'A session with this name and date already exists at an overlapping time.');
                        break;
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->only(['name', 'date', 'start_time', 'end_time', 'capacity']);
            
            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('images/sessions', 'public');
                $data['image'] = $imagePath;
            }

            $session = GymSession::create($data);

            // Return session with full image URL
            $sessionData = $session->toArray();
            if ($session->image) {
                $sessionData['image'] = asset('storage/' . $session->image);
            }

            return response()->json([
                'success' => true,
                'message' => 'Gym session created successfully',
                'data' => $sessionData
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create gym session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a gym session (Admin only)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'capacity' => 'sometimes|integer|min:1',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        // Custom validation for end_time after start_time and past date/time check
        $validator->after(function ($validator) use ($request, $id) {
            if ($request->has('start_time') && $request->has('end_time')) {
                $startTime = Carbon::createFromFormat('H:i', $request->start_time);
                $endTime = Carbon::createFromFormat('H:i', $request->end_time);
                
                if ($endTime->lte($startTime)) {
                    $validator->errors()->add('end_time', 'The end time must be after the start time.');
                }
            }
            
            // Check if session date/time is in the past
            // Get existing session to use current values if not provided
            $session = GymSession::find($id);
            if ($session) {
                $sessionDate = $request->has('date') ? Carbon::parse($request->date) : $session->date;
                $sessionStartTime = $request->has('start_time') 
                    ? Carbon::createFromFormat('H:i', $request->start_time) 
                    : Carbon::createFromFormat('H:i', $session->start_time);
                
                $sessionDateTime = Carbon::parse($sessionDate->format('Y-m-d') . ' ' . $sessionStartTime->format('H:i:s'));
                
                if ($sessionDateTime->isPast()) {
                    $validator->errors()->add('date', 'Cannot update session to have a past date and time.');
                }
                
                // Check for overlapping sessions with same name and date - exclude current session
                $name = $request->has('name') ? $request->name : $session->name;
                $date = $request->has('date') ? $request->date : $session->date;
                $startTime = $request->has('start_time') ? $request->start_time : $session->start_time;
                $endTime = $request->has('end_time') ? $request->end_time : $session->end_time;
                
                $existingSessions = GymSession::where('name', $name)
                    ->whereDate('date', $date)
                    ->where('id', '!=', $id) // Exclude current session
                    ->get();
                
                // Create a temporary session object to check overlaps
                $updatedSession = new GymSession([
                    'name' => $name,
                    'date' => $date,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
                
                foreach ($existingSessions as $existing) {
                    if ($updatedSession->overlapsWith($existing)) {
                        $validator->errors()->add('name', 'A session with this name and date already exists at an overlapping time.');
                        break;
                    }
                }
                
                // Check if capacity is being reduced below confirmed and pending bookings
                // Pending bookings reserve spots until admin confirms/rejects them
                if ($request->has('capacity')) {
                    $newCapacity = (int) $request->capacity;
                    $takenSpots = $session->bookings()
                        ->whereIn('status', ['confirmed', 'pending'])
                        ->count();
                    
                    if ($newCapacity < $takenSpots) {
                        $validator->errors()->add('capacity', "Cannot reduce capacity to {$newCapacity}. There are {$takenSpots} booking(s) (confirmed and pending). Minimum capacity must be {$takenSpots}.");
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $session = GymSession::findOrFail($id);
            $data = $request->only(['name', 'date', 'start_time', 'end_time', 'capacity']);

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($session->image) {
                    Storage::disk('public')->delete($session->image);
                }
                $imagePath = $request->file('image')->store('images/sessions', 'public');
                $data['image'] = $imagePath;
            } elseif ($request->has('remove_image') && $request->remove_image) {
                // Remove image if requested
                if ($session->image) {
                    Storage::disk('public')->delete($session->image);
                }
                $data['image'] = null;
            }

            $session->update($data);

            // Return session with full image URL
            $sessionData = $session->toArray();
            if ($session->image) {
                $sessionData['image'] = asset('storage/' . $session->image);
            } else {
                $sessionData['image'] = null;
            }

            return response()->json([
                'success' => true,
                'message' => 'Gym session updated successfully',
                'data' => $sessionData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update gym session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a gym session (Admin only)
     */
    public function destroy($id)
    {
        try {
            $session = GymSession::findOrFail($id);
            
            // Check for existing bookings
            $totalBookings = $session->bookings()->count();
            
            if ($totalBookings > 0) {
                $confirmedCount = $session->bookings()->where('status', 'confirmed')->count();
                $pendingCount = $session->bookings()->where('status', 'pending')->count();
                $queuedCount = $session->bookings()->where('status', 'queued')->count();
                $cancelledCount = $session->bookings()->where('status', 'cancelled')->count();
                
                $message = "Cannot delete session. It has {$totalBookings} booking(s)";
                $details = [];
                if ($confirmedCount > 0) $details[] = "{$confirmedCount} confirmed";
                if ($pendingCount > 0) $details[] = "{$pendingCount} pending";
                if ($queuedCount > 0) $details[] = "{$queuedCount} queued";
                if ($cancelledCount > 0) $details[] = "{$cancelledCount} cancelled";
                
                if (!empty($details)) {
                    $message .= " (" . implode(", ", $details) . ")";
                }
                $message .= ". Please cancel or delete all bookings first.";
                
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'bookings_count' => $totalBookings,
                    'confirmed_count' => $confirmedCount,
                    'pending_count' => $pendingCount,
                    'queued_count' => $queuedCount,
                    'cancelled_count' => $cancelledCount
                ], 400);
            }
            
            // Delete associated image if exists
            if ($session->image) {
                Storage::disk('public')->delete($session->image);
            }
            
            $session->delete();

            return response()->json([
                'success' => true,
                'message' => 'Gym session deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete gym session',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}



