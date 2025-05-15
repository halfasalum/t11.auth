<?php

namespace App\Services;

use App\Models\UserLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserLogService
{
    public function log($action, $details = null,$user = null, $company = null)
    {
       
        if (is_null($user)) {
            $token = JWTAuth::parseToken()->getPayload();
            $user = $token->get('user_id');
            $company = $token->get('company');
        }
        if (is_null($company)) {
            $token = JWTAuth::parseToken()->getPayload();
            $user = $token->get('user_id');
            $company = $token->get('company');
        }
        
        return UserLog::create([
            'user_id' => $user,
            'company' => $company,
            'action' => $action,
            'ip_address' => Request::ip(),
            'user_agent' => Request::header('User-Agent'),
            'details' => Request::path(). is_array($details) ? json_encode($details) : $details,
        ]);
    }
}
