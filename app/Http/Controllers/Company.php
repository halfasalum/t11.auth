<?php

namespace App\Http\Controllers;

use App\Models\Company as ModelsCompany;
use App\Models\role_permissions;
use App\Models\Roles;
use App\Models\User;
use App\Models\users_roles;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;

class Company extends Controller
{
    public function register(Request $request){
        try {
            $validated = $request->validate([
                'company_name' => 'bail|required|string|max:255',
                'company_phone' => 'bail|required|string|max:10',
                'company_email' => 'bail|required|string|max:255',
                'company_admin' => 'bail|required|string|max:255',
            ]);

            $data = [
                "company_name" => $request->company_name,
                "company_phone" => '255' . substr($request->company_phone, 1),
                "company_email" => $request->company_email,
            ];
            $company = ModelsCompany::create($data);
            $insertedId = $company->id;
            $user       = $request->company_admin;
            $password = mt_rand(10000000, 99999999);
            $phone = '255' . substr($request->company_phone, 1);

            $admin = new User();
            $admin->name = strtolower($user);
            $admin->first_name = $user;
            $admin->middle_name = $user;
            $admin->last_name = $user;
            $admin->phone = $phone; // Provide a default phone number
            $admin->user_phone = $request->company_phone; // Provide a default phone number
            $admin->super_admin = 0; // Set super_admin to true
            $admin->user_company = $insertedId; // Set user_company to 0
            $admin->password = Hash::make($password); // Hash the password 'admin'
            $admin->email = $request->company_email;    // Provide a default email
            $admin->save();
            $insertedAdminId = $admin->id;
            $roleData = [
                'role_name' => 'Admin',
                'company'   => $insertedId
            ];
            $role  = Roles::create($roleData);
            $roleId = $role->id;

            $permissions = [7,14,15,16,17,18,21,3,10,29,30,23];
            foreach($permissions as $permission){
                $data = [
                    'role_id' => $roleId,
                    'permission_id' => $permission,
                    'permission_status' => 1
                ];
                role_permissions::create($data);
            }
            $data = [
                'role_id' => $roleId,
                'user_id' => $insertedAdminId,
                'user_role_status' => 1
            ];
            users_roles::create($data);

            $notification = new Notifications();
            $message = "Habari, Username yako ni : ".$user. " na password ni : ". $password;
            $notification->sendSMS($phone, $message);


            return response()->json([
                'status' => 'success',
                'message' => 'Company created successfully',
                'module' => $company->company_name,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function list() {
        $companies = ModelsCompany::where('company_status', '!=', 3)
        ->select('id','company_name','company_phone','company_status','company_email','created_at')
        ->get();
        return response()->json(
            $companies
        );
    }
}
