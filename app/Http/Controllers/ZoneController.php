<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class ZoneController extends Controller
{
    public function register(Request $request){
        try {
            $validated = $request->validate([
                'zone_name' => 'bail|required|string|max:255',
                'branch' => 'bail|required',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
            $user_company = $user->get('company');
            $user_id = $user->get('user_id');
            $validated['registered_by'] = $user_id;
            $validated['company']       = $user_company;

            $zone = Zone::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone created successfully',
                'module' => $zone->company_name,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function list() {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $branches = Zone::where('zones.status', '!=', 3)
        ->where('zones.company',$user_company)
        ->select('zones.id','zone_name','branch_name','zones.created_at')
        ->join('branches', 'branches.id', '=', 'zones.branch')
            ->where('branches.status', 1)
        ->get();
        return response()->json(
            $branches
        );
    }

    public function getUserAssignedZones() {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $user_id = $user->get('user_id');
        $zones = Zone::where(['zone_users.user_id' => $user_id, 'zone_users.status' => 1])
            ->select('zones.id','zone_name')
            ->join('zone_users', 'zone_users.zone_id', '=', 'zones.id')
            ->get();
        return response()->json(
            $zones
        );
    }
}
