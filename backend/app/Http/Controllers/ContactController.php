<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ContactController extends Controller
{
    /**
     * Get all contacts for authenticated user
     */
    public function index(Request $request)
    {
        try {
            $contacts = $request->user()->contacts()->latest()->get();

            return response()->json([
                'success' => true,
                'data' => $contacts
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contacts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new contact
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'contact_picture' => 'nullable|string', // Base64 image
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $contactData = [
                'user_id' => $request->user()->id,
                'contact_name' => $request->contact_name,
                'contact_number' => $request->contact_number,
            ];

            // Handle base64 image if provided
            if ($request->contact_picture && str_starts_with($request->contact_picture, 'data:image')) {
                $contactData['contact_picture'] = $request->contact_picture;
            }

            $contact = Contact::create($contactData);

            return response()->json([
                'success' => true,
                'message' => 'Contact created successfully',
                'data' => $contact
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create contact',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single contact
     */
    public function show(Request $request, $id)
    {
        try {
            $contact = $request->user()->contacts()->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $contact
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found'
            ], 404);
        }
    }

    /**
     * Update a contact (FIXED)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'contact_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'contact_picture' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $contact = $request->user()->contacts()->findOrFail($id);

            // Prepare update data
            $contactData = [
                'contact_name' => $request->contact_name,
                'contact_number' => $request->contact_number,
            ];

            // FIXED: Only update picture if provided and valid base64
            if ($request->has('contact_picture') && $request->contact_picture) {
                // Check if it's a valid base64 image string
                if (str_starts_with($request->contact_picture, 'data:image')) {
                    $contactData['contact_picture'] = $request->contact_picture;
                }
            }

            // Update the contact
            $contact->update($contactData);

            // Refresh to get updated data
            $contact->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Contact updated successfully',
                'data' => $contact
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Contact not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update contact',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a contact
     */
    public function destroy(Request $request, $id)
    {
        try {
            $contact = $request->user()->contacts()->findOrFail($id);
            $contact->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contact deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete contact',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}