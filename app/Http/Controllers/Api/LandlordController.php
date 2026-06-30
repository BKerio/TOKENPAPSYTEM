<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Landlord;
use App\Models\Meter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LandlordController extends Controller
{
    /**
     * Return the authenticated landlord's own profile (for the dashboard).
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'landlord') {
            return response()->json(['status' => 403, 'message' => 'Landlord access only.'], 403);
        }

        $landlord = Landlord::with('user')->where('user_id', $user->id)->first();

        if (!$landlord) {
            return response()->json(['status' => 404, 'message' => 'Landlord profile not found.'], 404);
        }

        return response()->json([
            'status'   => 200,
            'landlord' => $landlord,
        ]);
    }

    /**
     * Meters assigned to the authenticated landlord.
     */
    public function getMeters(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'landlord') {
            return response()->json(['status' => 403, 'message' => 'Landlord access only.'], 403);
        }

        $landlord = Landlord::where('user_id', $user->id)->first();

        if (!$landlord) {
            return response()->json(['status' => 404, 'message' => 'Landlord profile not found.'], 404);
        }

        $meters = Meter::where('landlord_id', $landlord->id)->orderBy('meter_number')->get();

        return response()->json(['status' => 200, 'meters' => $meters]);
    }

    /**
     * List meters assigned to a landlord (admin).
     */
    public function adminMeters($id)
    {
        $landlord = Landlord::findOrFail($id);
        $meters = Meter::where('landlord_id', $landlord->id)->orderBy('meter_number')->get();

        return response()->json(['status' => 200, 'landlord' => $landlord, 'meters' => $meters]);
    }

    /**
     * Sync meter assignments for a landlord (admin).
     */
    public function syncMeters(Request $request, $id)
    {
        $landlord = Landlord::findOrFail($id);

        $validated = $request->validate([
            'meter_ids' => 'present|array',
            'meter_ids.*' => 'string',
        ]);

        $meterIds = array_values(array_unique($validated['meter_ids']));

        Meter::where('landlord_id', (string) $landlord->id)
            ->when(count($meterIds) > 0, fn ($q) => $q->whereNotIn('_id', $meterIds))
            ->update(['landlord_id' => null]);

        if (!empty($meterIds)) {
            Meter::whereIn('_id', $meterIds)->update(['landlord_id' => (string) $landlord->id]);
        }

        $meters = Meter::where('landlord_id', $landlord->id)->orderBy('meter_number')->get();

        return response()->json([
            'status' => 200,
            'message' => 'Meter assignments updated successfully',
            'meters' => $meters,
        ]);
    }

    /**
     * Display a listing of landlords.
     */
    public function index(Request $request)
    {
        $query = Landlord::with('user');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('payment_account', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $landlords = $query->paginate($request->per_page ?? 20);

        return response()->json($landlords);
    }

    /**
     * Store a newly created landlord.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|unique:users,email',
            'username'        => 'required|string|unique:users,username',
            'password'        => 'required|string|min:8',
            'phone'           => 'required|string|max:20',
            'payment_account' => 'required|string|max:255',
        ]);

        try {
            // Create the system user account
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role'     => 'landlord',
            ]);

            // Create the landlord profile
            $landlord = Landlord::create([
                'user_id'         => $user->id,
                'full_name'       => $validated['name'],
                'phone'           => $validated['phone'],
                'payment_account' => $validated['payment_account'],
                'status'          => 'active',
            ]);

            return response()->json([
                'message'  => 'Landlord registered successfully',
                'landlord' => $landlord->load('user'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to register landlord',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified landlord.
     */
    public function show($id)
    {
        $landlord = Landlord::with('user')->findOrFail($id);
        return response()->json($landlord);
    }

    /**
     * Update the specified landlord.
     */
    public function update(Request $request, $id)
    {
        $landlord = Landlord::findOrFail($id);
        $user     = $landlord->user;

        $validated = $request->validate([
            'name'            => 'sometimes|nullable|string|max:255',
            'email'           => 'sometimes|nullable|email|unique:users,email,' . $landlord->user_id . ',_id',
            'username'        => 'sometimes|nullable|string|unique:users,username,' . $landlord->user_id . ',_id',
            'password'        => 'nullable|string|min:8',
            'phone'           => 'sometimes|nullable|string|max:20',
            'payment_account' => 'sometimes|nullable|string|max:255',
            'status'          => 'sometimes|nullable|string|in:active,suspended',
        ]);

        try {
            // Update the linked user account
            if ($user) {
                $userData = [];
                if (isset($validated['name']))     $userData['name']     = $validated['name'];
                if (isset($validated['email']))    $userData['email']    = $validated['email'];
                if (isset($validated['username'])) $userData['username'] = $validated['username'];
                if (!empty($validated['password'])) $userData['password'] = Hash::make($validated['password']);

                if (!empty($userData)) {
                    $user->update($userData);
                }
            }

            // Update the landlord profile
            $landlordData = [];
            if (isset($validated['name']))            $landlordData['full_name']       = $validated['name'];
            if (isset($validated['phone']))           $landlordData['phone']           = $validated['phone'];
            if (isset($validated['payment_account'])) $landlordData['payment_account'] = $validated['payment_account'];
            if (isset($validated['status']))          $landlordData['status']          = $validated['status'];

            if (!empty($landlordData)) {
                $landlord->update($landlordData);
            }

            return response()->json([
                'message'  => 'Landlord updated successfully',
                'landlord' => $landlord->fresh('user'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update landlord',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified landlord and their user account.
     */
    public function destroy($id)
    {
        $landlord = Landlord::findOrFail($id);
        $user     = $landlord->user;

        try {
            $landlord->delete();
            if ($user) {
                $user->delete();
            }

            return response()->json(['message' => 'Landlord removed successfully']);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to remove landlord',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
