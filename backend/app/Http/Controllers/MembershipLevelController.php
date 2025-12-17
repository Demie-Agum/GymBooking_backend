<?php

namespace App\Http\Controllers;

use App\Models\MembershipLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MembershipLevelController extends Controller
{
    /**
     * Get all membership levels
     */
    public function index()
    {
        try {
            $levels = MembershipLevel::orderBy('priority', 'desc')
                ->orderBy('weekly_limit', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $levels
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch membership levels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new membership level (Admin only)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:membership_levels',
            'weekly_limit' => 'nullable|integer|min:0',
            'priority' => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $level = MembershipLevel::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Membership level created successfully',
                'data' => $level
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create membership level',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a membership level (Admin only)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:membership_levels,name,' . $id,
            'weekly_limit' => 'nullable|integer|min:0',
            'priority' => 'sometimes|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $level = MembershipLevel::findOrFail($id);
            $level->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Membership level updated successfully',
                'data' => $level
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update membership level',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a membership level (Admin only)
     */
    public function destroy($id)
    {
        try {
            $level = MembershipLevel::findOrFail($id);
            $level->delete();

            return response()->json([
                'success' => true,
                'message' => 'Membership level deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete membership level',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}



