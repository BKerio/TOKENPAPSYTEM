<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meter;
use App\Models\User;
use App\Models\Landlord;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MeterController extends Controller
{
    /** Only vendors are blocked from creating/assigning/deleting meters. Everyone else (admin, or role missing) is allowed. */
    private static function isVendorUser($user): bool
    {
        if (!$user) {
            return false;
        }
        $role = $user->role ?? null;
        if ($role === null || $role === '') {
            return false;
        }
        return strtolower(trim((string) $role)) === 'vendor';
    }

    /**
     * Display a listing of meters.
     * Admin/system_admin: all meters. Vendor: only their assigned meters.
     */
    public function index(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = Auth::user();
        $query = Meter::with(['vendor', 'landlord']);

        $role = $user->role ?? null;

        // Only vendors are restricted to their own meters; admins see all
        if ($role === 'vendor') {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if (!$vendor) {
                return response()->json([
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $request->get('per_page', 10),
                    'total' => 0,
                ]);
            }
            $query->where('vendor_id', (string) $vendor->id);
        } elseif ($role === 'landlord') {
            $landlord = Landlord::where('user_id', $user->id)->first();
            if (!$landlord) {
                return response()->json([
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $request->get('per_page', 10),
                    'total' => 0,
                ]);
            }
            $query->where('landlord_id', (string) $landlord->id);
        }

        // Search by meter number
        if ($request->filled('search')) {
            $query->where('meter_number', 'like', '%' . $request->search . '%');
        }

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage >= 1 && $perPage <= 1000 ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    /**
     * Store a newly created meter. (Admin only)
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || self::isVendorUser($user)) {
            return response()->json(['message' => 'Only administrators can create or assign meters.'], 403);
        }

        $input = $request->all();
        if (isset($input['vendor_id']) && $input['vendor_id'] === '') {
            $input['vendor_id'] = null;
        }
        if (isset($input['landlord_id']) && $input['landlord_id'] === '') {
            $input['landlord_id'] = null;
        }
        // Pricing is set by the vendor, not admin. Default to 0 when creating.
        if (!array_key_exists('price_per_unit', $input) || $input['price_per_unit'] === '' || $input['price_per_unit'] === null) {
            $input['price_per_unit'] = 0;
        }
        $validated = validator($input, [
            'meter_number' => 'required|string|unique:meters,meter_number',
            'type' => 'required|string',
            'initial_reading' => 'numeric|min:0',
            'price_per_unit' => 'numeric|min:0',
            'vendor_id' => 'nullable|exists:vendors,_id',
            'landlord_id' => 'nullable|exists:landlords,_id',
            'status' => 'string|in:active,inactive,maintenance',
            'sgc' => 'nullable|integer',
            'krn' => 'nullable|integer',
            'ti' => 'nullable|integer',
            'ea' => 'nullable|integer',
            'ken' => 'nullable|integer',
        ])->validate();

        $meter = Meter::create($validated);

        return response()->json([
            'message' => 'Meter created successfully',
            'meter' => $meter->load(['vendor', 'landlord'])
        ], 201);
    }

    /**
     * Display the specified meter.
     */
    public function show(string $id)
    {
        $user = Auth::user();
        $meter = Meter::with(['vendor', 'landlord', 'customers'])->findOrFail($id);

        if ($user->role === 'vendor') {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if (!$vendor || (string) $meter->vendor_id !== (string) $vendor->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($user->role === 'landlord') {
            $landlord = Landlord::where('user_id', $user->id)->first();
            if (!$landlord || (string) $meter->landlord_id !== (string) $landlord->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        return response()->json($meter);
    }

    /**
     * Update the specified meter.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        $meter = Meter::findOrFail($id);

        // Security check for vendors
        if ($user->role === 'vendor') {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if (!$vendor || (string) $meter->vendor_id !== (string) $vendor->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($user->role === 'landlord') {
            $landlord = Landlord::where('user_id', $user->id)->first();
            if (!$landlord || (string) $meter->landlord_id !== (string) $landlord->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $input = $request->all();
        if (array_key_exists('vendor_id', $input) && $input['vendor_id'] === '') {
            $input['vendor_id'] = null;
        }
        if (array_key_exists('landlord_id', $input) && $input['landlord_id'] === '') {
            $input['landlord_id'] = null;
        }
        $validated = validator($input, [
            'meter_number' => 'sometimes|string|unique:meters,meter_number,' . $id . ',_id',
            'type' => 'sometimes|string',
            'price_per_unit' => 'sometimes|numeric|min:0',
            'vendor_id' => 'sometimes|nullable|exists:vendors,_id',
            'landlord_id' => 'sometimes|nullable|exists:landlords,_id',
            'status' => 'sometimes|string|in:active,inactive,maintenance',
            'sgc' => 'sometimes|nullable|integer',
            'krn' => 'sometimes|nullable|integer',
            'ti' => 'sometimes|nullable|integer',
            'ea' => 'sometimes|nullable|integer',
            'ken' => 'sometimes|nullable|integer',
        ])->validate();

        // Only vendors can set or change price_per_unit; admins cannot.
        if (self::isVendorUser($user)) {
            $allowedForVendor = ['price_per_unit'];
            $validated = array_intersect_key($validated, array_flip($allowedForVendor));
        } else {
            // Admin: allow meter number, type, vendor_id, status — but not price (vendor dictates pricing)
            unset($validated['price_per_unit']);
        }

        $meter->update($validated);

        return response()->json([
            'message' => 'Meter updated successfully',
            'meter' => $meter->load(['vendor', 'landlord'])
        ]);
    }

    /**
     * Remove the specified meter. (Admin only)
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        if (!$user || self::isVendorUser($user)) {
            return response()->json(['message' => 'Only administrators can delete meters.'], 403);
        }

        $meter = Meter::findOrFail($id);
        $meter->delete();

        return response()->json(['message' => 'Meter deleted successfully']);
    }
}
