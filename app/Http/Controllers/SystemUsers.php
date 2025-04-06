<?php

namespace App\Http\Controllers;

use App\Models\BranchUser;
use App\Models\User;
use App\Models\users_roles;
use App\Models\ZoneUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class SystemUsers extends Controller
{
    public function list()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $users = User::where(['user_company' => $user_company])
            ->select('id', 'name', 'email', 'first_name', 'last_name', 'status', 'created_at', 'phone')
            ->where("status", '!=', 3)
            ->get();
        return response()->json(
            $users
        );
    }

    public function getUserRoles() {}

    public function registerUserRoles(Request $request)
    {

        try {
            $request->validate([
                'roles' => 'required',
                'user_id' => 'required',
            ]);
            $user_id = $request->user_id;
            users_roles::where('user_id', $user_id)
                ->update(['user_role_status' => 3]);
            $roles = $request->roles;
            if (sizeof($roles) > 0) {
                foreach ($roles as $role) {
                    $data = [
                        'role_id' => $role,
                        'user_id' => $user_id,
                        'user_role_status' => 1
                    ];
                    users_roles::create($data);
                }
            }
            return response()->json([
                'status' => 'success',
                'message' => 'User role is successfully updated',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerSchoolAdmin(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'bail|required|string|max:255',
                'middle_name' => 'bail|required|string|max:255',
                'last_name' => 'bail|required|string|max:255',
                'email' => 'required|email',
                'phone' => 'required',
                'user_school' => 'required',
                'birth_date' => 'required',
            ]);
            $validated['name'] = strtolower($validated['first_name'] . '.' . $validated['last_name']);
            $validated['password'] = Hash::make('password');
            $validated['user_type'] = 1;
            User::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'System admin created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerUser(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->getPayload();
            $company = $user->get('company');
            $validated = $request->validate([
                'first_name' => 'bail|required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'required|numeric|starts_with:06,07|digits:10|unique:App\Models\User,user_phone',                
                'email' => 'email|unique:App\Models\User,email',
            ]);
            $username = strtolower($request->first_name . '.' . $request->last_name);
            $existingUser = User::where('name', $username)->first();
            $submittedPhone = $request->phone;
            $phone = '255' . substr($request->phone, 1);
            $validated['phone'] = $phone;
            $validated['user_phone'] = $request->phone;
            $validated['user_company'] = $company;
            if ($existingUser) {
                $validated['name'] = $submittedPhone;
                $name = $submittedPhone;
            } else {
                $validated['name'] = $username;
                $name = $username;
            }
            //$validated['name'] = strtolower($validated['first_name'] . '.' . $validated['last_name']);
            $password = Str::random(8);
            $validated['password'] = Hash::make($password);
            $created_user = User::create($validated);
            $user_id = $created_user->id;
            $roles = $request->roles;
            if (sizeof($roles) > 0) {
                foreach ($roles as $role) {
                    $data = [
                        'role_id' => $role,
                        'user_id' => $user_id,
                        'user_role_status' => 1
                    ];
                    users_roles::create($data);
                }
            }
            
            $branches = $request->branches;
            if(sizeof($branches) > 0){
                foreach($branches as $branch){
                    $data = [
                        'branch_id' => $branch,
                        'user_id'   => $user_id
                    ];
                    BranchUser::create($data);
                }
                $zones = [];
            }else{
                $zones = $request->zones;
            }

            //$zones = $request->zones;
            if(sizeof($zones) > 0){
                foreach($zones as $zone){
                    $data = [
                        'user_id' => $user_id,
                        'zone_id'   => $zone
                    ];
                    ZoneUser::create($data);
                }
            }
            
            $notification = new Notifications();
            $message = "Habari, Username yako ni : ".$name. " na password ni : ". $password;
            $notification->sendSMS($phone, $message);
            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
