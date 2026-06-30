<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\LandlordTenant;
use App\Models\Property;
use App\Models\PropertyRoute;
use App\Models\PropertyStreet;
use App\Models\PropertyUnit;
use App\Models\PropertyZone;
use App\Models\Meter;
use Illuminate\Http\Request;

class PropertyHierarchyController extends Controller
{
    public function summary(Request $request)
    {
        $landlord = $this->resolveLandlord($request);

        return response()->json([
            'status' => 200,
            'summary' => [
                'properties' => Property::where('landlord_id', $landlord->id)->count(),
                'zones' => PropertyZone::where('landlord_id', $landlord->id)->count(),
                'routes' => PropertyRoute::where('landlord_id', $landlord->id)->count(),
                'streets' => PropertyStreet::where('landlord_id', $landlord->id)->count(),
                'units' => PropertyUnit::where('landlord_id', $landlord->id)->count(),
                'tenants' => LandlordTenant::where('landlord_id', $landlord->id)->count(),
            ],
        ]);
    }

    public function hierarchy(Request $request, string $propertyId)
    {
        $landlord = $this->resolveLandlord($request);
        $property = $this->resolveProperty($landlord, $propertyId);

        $zones = PropertyZone::where('property_id', $property->id)->orderBy('name')->get();
        $routes = PropertyRoute::where('property_id', $property->id)->orderBy('name')->get();
        $streets = PropertyStreet::where('property_id', $property->id)->orderBy('name')->get();
        $units = PropertyUnit::where('property_id', $property->id)->orderBy('name')->get();
        $tenants = LandlordTenant::where('property_id', $property->id)->orderBy('name')->get();
        $meterIds = $units->pluck('meter_id')->filter()->unique()->values()->all();
        $metersById = !empty($meterIds)
            ? Meter::whereIn('_id', $meterIds)->get()->keyBy(fn (Meter $m) => (string) $m->id)
            : collect();
        $assignedMeters = Meter::where('landlord_id', $landlord->id)->orderBy('meter_number')->get();
        $zonesById = $zones->keyBy(fn (PropertyZone $z) => (string) $z->id);
        $routesById = $routes->keyBy(fn (PropertyRoute $r) => (string) $r->id);
        $streetsById = $streets->keyBy(fn (PropertyStreet $s) => (string) $s->id);

        $tree = $zones->map(function (PropertyZone $zone) use ($routes, $streets, $units, $tenants, $metersById) {
            $zoneRoutes = $routes->where('zone_id', (string) $zone->id)->values()->map(function (PropertyRoute $route) use ($streets, $units, $tenants, $metersById) {
                $routeStreets = $streets->where('route_id', (string) $route->id)->values()->map(function (PropertyStreet $street) use ($units, $tenants, $metersById) {
                    return [
                        'id' => $street->id,
                        'name' => $street->name,
                        'status' => $street->status,
                        'type' => 'street',
                        'units' => $this->unitsForParent($units, 'street', $street->id, $metersById),
                        'tenants' => $this->tenantsForNode($tenants, 'street', $street->id),
                    ];
                });

                return [
                    'id' => $route->id,
                    'name' => $route->name,
                    'status' => $route->status,
                    'type' => 'route',
                    'streets' => $routeStreets,
                    'units' => $this->unitsForParent($units, 'route', $route->id, $metersById),
                    'tenants' => $this->tenantsForNode($tenants, 'route', $route->id),
                ];
            });

            return [
                'id' => $zone->id,
                'name' => $zone->name,
                'status' => $zone->status,
                'type' => 'zone',
                'routes' => $zoneRoutes,
                'units' => $this->unitsForParent($units, 'zone', $zone->id, $metersById),
                'tenants' => $this->tenantsForNode($tenants, 'zone', $zone->id),
            ];
        });

        $allUnits = $units->map(function (PropertyUnit $unit) use ($metersById, $zonesById, $routesById, $streetsById) {
            $meterId = $unit->meter_id ? (string) $unit->meter_id : null;
            $meter = $meterId && $metersById ? $metersById->get($meterId) : null;

            return [
                'id' => $unit->id,
                'name' => $unit->name,
                'unit_number' => $unit->unit_number,
                'status' => $unit->status,
                'parent_type' => $unit->parent_type,
                'parent_id' => $unit->parent_id,
                'location_path' => $this->unitLocationPath($unit, $zonesById, $routesById, $streetsById),
                'meter_id' => $meterId,
                'meter_number' => $meter?->meter_number,
            ];
        })->values();

        return response()->json([
            'status' => 200,
            'property' => $property,
            'tree' => $tree,
            'all_units' => $allUnits,
            'tenants' => $tenants,
            'assigned_meters' => $assignedMeters->map(fn (Meter $m) => [
                'id' => $m->id,
                'meter_number' => $m->meter_number,
                'status' => $m->status,
            ]),
        ]);
    }

    public function storeZone(Request $request, string $propertyId)
    {
        $landlord = $this->resolveLandlord($request);
        $property = $this->resolveProperty($landlord, $propertyId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $zone = PropertyZone::create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['status' => 201, 'zone' => $zone], 201);
    }

    public function updateZone(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $zone = PropertyZone::findOrFail($id);
        $this->assertLandlordOwns($landlord, $zone->landlord_id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $zone->update($validated);

        return response()->json(['status' => 200, 'zone' => $zone->fresh()]);
    }

    public function destroyZone(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $zone = PropertyZone::findOrFail($id);
        $this->assertLandlordOwns($landlord, $zone->landlord_id);

        $zoneId = (string) $zone->id;
        $routeIds = PropertyRoute::where('zone_id', $zoneId)->pluck('id')->map(fn ($rid) => (string) $rid);
        $streetIds = PropertyStreet::whereIn('route_id', $routeIds)->pluck('id')->map(fn ($sid) => (string) $sid);

        PropertyUnit::where('parent_type', 'zone')->where('parent_id', $zoneId)->delete();
        PropertyUnit::where('parent_type', 'route')->whereIn('parent_id', $routeIds)->delete();
        PropertyUnit::where('parent_type', 'street')->whereIn('parent_id', $streetIds)->delete();
        LandlordTenant::where('node_type', 'zone')->where('node_id', $zoneId)->delete();
        LandlordTenant::where('node_type', 'route')->whereIn('node_id', $routeIds)->delete();
        LandlordTenant::where('node_type', 'street')->whereIn('node_id', $streetIds)->delete();
        PropertyStreet::whereIn('route_id', $routeIds)->delete();
        PropertyRoute::where('zone_id', $zoneId)->delete();
        $zone->delete();

        return response()->json(['status' => 200, 'message' => 'Zone deleted']);
    }

    public function storeRoute(Request $request, string $zoneId)
    {
        $landlord = $this->resolveLandlord($request);
        $zone = PropertyZone::findOrFail($zoneId);
        $this->assertLandlordOwns($landlord, $zone->landlord_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $route = PropertyRoute::create([
            'landlord_id' => $landlord->id,
            'property_id' => $zone->property_id,
            'zone_id' => $zone->id,
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['status' => 201, 'route' => $route], 201);
    }

    public function updateRoute(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $route = PropertyRoute::findOrFail($id);
        $this->assertLandlordOwns($landlord, $route->landlord_id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $route->update($validated);

        return response()->json(['status' => 200, 'route' => $route->fresh()]);
    }

    public function destroyRoute(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $route = PropertyRoute::findOrFail($id);
        $this->assertLandlordOwns($landlord, $route->landlord_id);

        $routeId = (string) $route->id;
        $streetIds = PropertyStreet::where('route_id', $routeId)->pluck('id')->map(fn ($sid) => (string) $sid);

        PropertyUnit::where('parent_type', 'route')->where('parent_id', $routeId)->delete();
        PropertyUnit::where('parent_type', 'street')->whereIn('parent_id', $streetIds)->delete();
        LandlordTenant::where('node_type', 'route')->where('node_id', $routeId)->delete();
        LandlordTenant::where('node_type', 'street')->whereIn('node_id', $streetIds)->delete();
        PropertyStreet::where('route_id', $routeId)->delete();
        $route->delete();

        return response()->json(['status' => 200, 'message' => 'Route deleted']);
    }

    public function storeStreet(Request $request, string $routeId)
    {
        $landlord = $this->resolveLandlord($request);
        $route = PropertyRoute::findOrFail($routeId);
        $this->assertLandlordOwns($landlord, $route->landlord_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $street = PropertyStreet::create([
            'landlord_id' => $landlord->id,
            'property_id' => $route->property_id,
            'route_id' => $route->id,
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['status' => 201, 'street' => $street], 201);
    }

    public function updateStreet(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $street = PropertyStreet::findOrFail($id);
        $this->assertLandlordOwns($landlord, $street->landlord_id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $street->update($validated);

        return response()->json(['status' => 200, 'street' => $street->fresh()]);
    }

    public function destroyStreet(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $street = PropertyStreet::findOrFail($id);
        $this->assertLandlordOwns($landlord, $street->landlord_id);

        $streetId = (string) $street->id;
        PropertyUnit::where('parent_type', 'street')->where('parent_id', $streetId)->delete();
        LandlordTenant::where('node_type', 'street')->where('node_id', $streetId)->delete();
        $street->delete();

        return response()->json(['status' => 200, 'message' => 'Street deleted']);
    }

    public function storeUnit(Request $request)
    {
        $landlord = $this->resolveLandlord($request);

        $validated = $request->validate([
            'property_id' => 'required|string',
            'parent_type' => 'required|string|in:zone,route,street',
            'parent_id' => 'required|string',
            'name' => 'required|string|max:255',
            'unit_number' => 'nullable|string|max:100',
            'meter_id' => 'nullable|string|exists:meters,_id',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $property = $this->resolveProperty($landlord, $validated['property_id']);
        $this->assertParentBelongsToProperty($validated['parent_type'], $validated['parent_id'], $property->id, $landlord->id);

        $meterId = $validated['meter_id'] ?? null;
        if ($meterId) {
            $this->assertMeterBelongsToLandlord($landlord, $meterId);
        }

        $unit = PropertyUnit::create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'parent_type' => $validated['parent_type'],
            'parent_id' => $validated['parent_id'],
            'name' => $validated['name'],
            'unit_number' => $validated['unit_number'] ?? null,
            'meter_id' => $meterId,
            'status' => $validated['status'] ?? 'active',
        ]);

        if ($meterId) {
            $this->unlinkMeterFromOtherUnits($landlord, $meterId, (string) $unit->id);
        }

        $meter = $meterId ? Meter::find($meterId) : null;

        return response()->json([
            'status' => 201,
            'unit' => array_merge($unit->toArray(), [
                'meter_number' => $meter?->meter_number,
            ]),
        ], 201);
    }

    /**
     * Create multiple units under one parent node (e.g. 10 units for a property block).
     */
    public function storeBulkUnits(Request $request, string $propertyId)
    {
        $landlord = $this->resolveLandlord($request);
        $property = $this->resolveProperty($landlord, $propertyId);

        $validated = $request->validate([
            'parent_type' => 'required|string|in:zone,route,street',
            'parent_id' => 'required|string',
            'count' => 'required|integer|min:1|max:100',
            'name_prefix' => 'required|string|max:100',
            'start_number' => 'sometimes|integer|min:1',
            'assignments' => 'sometimes|array',
            'assignments.*.meter_id' => 'nullable|string|exists:meters,_id',
        ]);

        $this->assertParentBelongsToProperty(
            $validated['parent_type'],
            $validated['parent_id'],
            $property->id,
            $landlord->id
        );

        $startNumber = $validated['start_number'] ?? 1;
        $assignments = $validated['assignments'] ?? [];
        $meterIdsInBatch = collect($assignments)->pluck('meter_id')->filter()->values();
        if ($meterIdsInBatch->count() !== $meterIdsInBatch->unique()->count()) {
            return response()->json([
                'status' => 422,
                'message' => 'Each meter can only be linked to one unit.',
            ], 422);
        }

        $created = [];

        for ($i = 0; $i < $validated['count']; $i++) {
            $number = $startNumber + $i;
            $name = trim($validated['name_prefix']) . ' ' . $number;
            $meterId = $assignments[$i]['meter_id'] ?? null;

            if ($meterId) {
                $this->assertMeterBelongsToLandlord($landlord, $meterId);
            }

            $unit = PropertyUnit::create([
                'landlord_id' => $landlord->id,
                'property_id' => $property->id,
                'parent_type' => $validated['parent_type'],
                'parent_id' => $validated['parent_id'],
                'name' => $name,
                'unit_number' => (string) $number,
                'meter_id' => $meterId,
                'status' => 'active',
            ]);

            if ($meterId) {
                $this->unlinkMeterFromOtherUnits($landlord, $meterId, (string) $unit->id);
            }

            $meter = $meterId ? Meter::find($meterId) : null;
            $created[] = [
                'id' => $unit->id,
                'name' => $unit->name,
                'unit_number' => $unit->unit_number,
                'meter_id' => $meterId,
                'meter_number' => $meter?->meter_number,
            ];
        }

        return response()->json([
            'status' => 201,
            'message' => count($created) . ' units created successfully',
            'units' => $created,
        ], 201);
    }

    /**
     * Bulk assign meters to units for a property.
     */
    public function syncUnitMeters(Request $request, string $propertyId)
    {
        $landlord = $this->resolveLandlord($request);
        $property = $this->resolveProperty($landlord, $propertyId);

        $validated = $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.unit_id' => 'required|string',
            'assignments.*.meter_id' => 'nullable|string|exists:meters,_id',
        ]);

        $unitIds = collect($validated['assignments'])->pluck('unit_id')->unique()->values();
        $propertyUnits = PropertyUnit::where('property_id', $property->id)
            ->where('landlord_id', $landlord->id)
            ->whereIn('_id', $unitIds->all())
            ->get()
            ->keyBy(fn (PropertyUnit $u) => (string) $u->id);

        if ($propertyUnits->count() !== $unitIds->count()) {
            return response()->json([
                'status' => 422,
                'message' => 'One or more units do not belong to this property.',
            ], 422);
        }

        $meterIdsInRequest = collect($validated['assignments'])
            ->pluck('meter_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($meterIdsInRequest as $meterId) {
            $this->assertMeterBelongsToLandlord($landlord, $meterId);
        }

        if ($meterIdsInRequest->count() !== $meterIdsInRequest->unique()->count()) {
            return response()->json([
                'status' => 422,
                'message' => 'Each meter can only be linked to one unit.',
            ], 422);
        }

        $updated = [];
        foreach ($validated['assignments'] as $row) {
            $unit = $propertyUnits[(string) $row['unit_id']];
            $meterId = $row['meter_id'] ?? null;

            if ($meterId) {
                $this->unlinkMeterFromOtherUnits($landlord, $meterId, (string) $unit->id);
            }

            $unit->update(['meter_id' => $meterId]);
            $meter = $meterId ? Meter::find($meterId) : null;

            $updated[] = [
                'unit_id' => $unit->id,
                'unit_name' => $unit->name,
                'meter_id' => $meterId,
                'meter_number' => $meter?->meter_number,
            ];
        }

        return response()->json([
            'status' => 200,
            'message' => 'Meter assignments saved',
            'assignments' => $updated,
        ]);
    }

    public function updateUnit(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $unit = PropertyUnit::findOrFail($id);
        $this->assertLandlordOwns($landlord, $unit->landlord_id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'unit_number' => 'nullable|string|max:100',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $unit->update($validated);

        return response()->json(['status' => 200, 'unit' => $unit->fresh()]);
    }

    public function destroyUnit(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $unit = PropertyUnit::findOrFail($id);
        $this->assertLandlordOwns($landlord, $unit->landlord_id);

        LandlordTenant::where('node_type', 'unit')->where('node_id', (string) $unit->id)->delete();
        $unit->delete();

        return response()->json(['status' => 200, 'message' => 'Unit deleted']);
    }

    /**
     * Link or unlink an admin-assigned meter to a property unit.
     */
    public function assignUnitMeter(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $unit = PropertyUnit::findOrFail($id);
        $this->assertLandlordOwns($landlord, $unit->landlord_id);

        $validated = $request->validate([
            'meter_id' => 'nullable|string|exists:meters,_id',
        ]);

        $meterId = $validated['meter_id'] ?? null;

        if ($meterId) {
            $meter = Meter::findOrFail($meterId);
            if ((string) $meter->landlord_id !== (string) $landlord->id) {
                return response()->json([
                    'status' => 403,
                    'message' => 'This meter is not assigned to your landlord account.',
                ], 403);
            }

            $this->unlinkMeterFromOtherUnits($landlord, $meterId, (string) $unit->id);
        }

        $unit->update(['meter_id' => $meterId]);

        $meter = $meterId ? Meter::find($meterId) : null;

        return response()->json([
            'status' => 200,
            'message' => $meterId ? 'Meter linked to unit successfully' : 'Meter unlinked from unit',
            'unit' => [
                'id' => $unit->id,
                'name' => $unit->name,
                'meter_id' => $unit->meter_id,
                'meter_number' => $meter?->meter_number,
            ],
        ]);
    }

    public function indexTenants(Request $request)
    {
        $landlord = $this->resolveLandlord($request);
        $query = LandlordTenant::where('landlord_id', $landlord->id);

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->property_id);
        }

        return response()->json(['status' => 200, 'tenants' => $query->orderBy('name')->get()]);
    }

    public function storeTenant(Request $request)
    {
        $landlord = $this->resolveLandlord($request);

        $validated = $request->validate([
            'property_id' => 'required|string',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'node_type' => 'required|string|in:unit,zone,route,street',
            'node_id' => 'required|string',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $property = $this->resolveProperty($landlord, $validated['property_id']);
        $this->assertNodeBelongsToProperty($validated['node_type'], $validated['node_id'], $property->id, $landlord->id);

        $tenant = LandlordTenant::create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'node_type' => $validated['node_type'],
            'node_id' => $validated['node_id'],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json(['status' => 201, 'tenant' => $tenant], 201);
    }

    public function updateTenant(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $tenant = LandlordTenant::findOrFail($id);
        $this->assertLandlordOwns($landlord, $tenant->landlord_id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:30',
            'email' => 'nullable|email|max:255',
            'node_type' => 'sometimes|string|in:unit,zone,route,street',
            'node_id' => 'sometimes|string',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        if (isset($validated['node_type'], $validated['node_id'])) {
            $this->assertNodeBelongsToProperty($validated['node_type'], $validated['node_id'], $tenant->property_id, $landlord->id);
        }

        $tenant->update($validated);

        return response()->json(['status' => 200, 'tenant' => $tenant->fresh()]);
    }

    public function destroyTenant(Request $request, string $id)
    {
        $landlord = $this->resolveLandlord($request);
        $tenant = LandlordTenant::findOrFail($id);
        $this->assertLandlordOwns($landlord, $tenant->landlord_id);
        $tenant->delete();

        return response()->json(['status' => 200, 'message' => 'Tenant removed']);
    }

    protected function resolveLandlord(Request $request): Landlord
    {
        $user = $request->user();
        if (!$user || $user->role !== 'landlord') {
            abort(response()->json(['status' => 403, 'message' => 'Landlord access only.'], 403));
        }

        $landlord = Landlord::where('user_id', $user->id)->first();
        if (!$landlord) {
            abort(response()->json(['status' => 404, 'message' => 'Landlord profile not found.'], 404));
        }

        return $landlord;
    }

    protected function resolveProperty(Landlord $landlord, string $propertyId): Property
    {
        $property = Property::where('landlord_id', $landlord->id)->find($propertyId);
        if (!$property) {
            abort(response()->json(['status' => 404, 'message' => 'Property not found.'], 404));
        }

        return $property;
    }

    protected function assertLandlordOwns(Landlord $landlord, string $ownerId): void
    {
        if ((string) $ownerId !== (string) $landlord->id) {
            abort(response()->json(['status' => 403, 'message' => 'Access denied.'], 403));
        }
    }

    protected function assertParentBelongsToProperty(string $parentType, string $parentId, string $propertyId, string $landlordId): void
    {
        $model = match ($parentType) {
            'zone' => PropertyZone::find($parentId),
            'route' => PropertyRoute::find($parentId),
            'street' => PropertyStreet::find($parentId),
            default => null,
        };

        if (!$model || (string) $model->property_id !== (string) $propertyId || (string) $model->landlord_id !== (string) $landlordId) {
            abort(response()->json(['status' => 422, 'message' => 'Invalid parent node for this property.'], 422));
        }
    }

    protected function assertNodeBelongsToProperty(string $nodeType, string $nodeId, string $propertyId, string $landlordId): void
    {
        if ($nodeType === 'unit') {
            $unit = PropertyUnit::find($nodeId);
            if (!$unit || (string) $unit->property_id !== (string) $propertyId || (string) $unit->landlord_id !== (string) $landlordId) {
                abort(response()->json(['status' => 422, 'message' => 'Invalid unit for this property.'], 422));
            }
            return;
        }

        $this->assertParentBelongsToProperty($nodeType, $nodeId, $propertyId, $landlordId);
    }

    protected function unitsForParent($units, string $parentType, $parentId, $metersById = null)
    {
        return $units
            ->where('parent_type', $parentType)
            ->where('parent_id', (string) $parentId)
            ->values()
            ->map(function (PropertyUnit $unit) use ($metersById) {
                $meterId = $unit->meter_id ? (string) $unit->meter_id : null;
                $meter = $meterId && $metersById ? $metersById->get($meterId) : null;

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'unit_number' => $unit->unit_number,
                    'status' => $unit->status,
                    'type' => 'unit',
                    'parent_type' => $unit->parent_type,
                    'parent_id' => $unit->parent_id,
                    'meter_id' => $meterId,
                    'meter_number' => $meter?->meter_number,
                ];
            });
    }

    protected function assertMeterBelongsToLandlord(Landlord $landlord, string $meterId): void
    {
        $meter = Meter::find($meterId);
        if (!$meter || (string) $meter->landlord_id !== (string) $landlord->id) {
            abort(response()->json([
                'status' => 403,
                'message' => 'This meter is not assigned to your landlord account.',
            ], 403));
        }
    }

    protected function unlinkMeterFromOtherUnits(Landlord $landlord, string $meterId, string $exceptUnitId): void
    {
        PropertyUnit::where('landlord_id', $landlord->id)
            ->where('meter_id', $meterId)
            ->where('_id', '!=', $exceptUnitId)
            ->update(['meter_id' => null]);
    }

    protected function unitLocationPath(
        PropertyUnit $unit,
        $zonesById,
        $routesById,
        $streetsById
    ): string {
        $parts = [];

        if ($unit->parent_type === 'street') {
            $street = $streetsById->get((string) $unit->parent_id);
            if ($street) {
                $parts[] = $street->name;
                $route = $routesById->get((string) $street->route_id);
                if ($route) {
                    $parts[] = $route->name;
                    $zone = $zonesById->get((string) $route->zone_id);
                    if ($zone) {
                        $parts[] = $zone->name;
                    }
                }
            }
        } elseif ($unit->parent_type === 'route') {
            $route = $routesById->get((string) $unit->parent_id);
            if ($route) {
                $parts[] = $route->name;
                $zone = $zonesById->get((string) $route->zone_id);
                if ($zone) {
                    $parts[] = $zone->name;
                }
            }
        } elseif ($unit->parent_type === 'zone') {
            $zone = $zonesById->get((string) $unit->parent_id);
            if ($zone) {
                $parts[] = $zone->name;
            }
        }

        return implode(' → ', array_reverse($parts)) ?: ucfirst($unit->parent_type);
    }

    protected function tenantsForNode($tenants, string $nodeType, $nodeId)
    {
        return $tenants
            ->where('node_type', $nodeType)
            ->where('node_id', (string) $nodeId)
            ->values()
            ->map(fn (LandlordTenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'status' => $tenant->status,
                'node_type' => $tenant->node_type,
                'node_id' => $tenant->node_id,
            ]);
    }
}
