<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class Notifications extends Controller
{
    public function sendSms($phone = '255657183285', $message = 'Test', $company = NULL)
    {

        
        $smsData                   = [
            'receiver'          => $phone,
            'contents'          => $message,
            'sent_date'         => date('Y-m-d'),
        ];
       

        $api_key = 'cd265ba2a9711dd6';
        $secret_key = 'MzA2ZjNiMDBjNjgwOTQ0Njc2ZjU0MmE5YmU1YzNkZGIwOTcwNzQ5ZWMwY2Q0OWVmM2QyZjI0NmJkNzlhY2VmZg==';

        $postData = array(
            'source_addr' => 'CMS',
            'encoding' => 0,
            'schedule_time' => '',
            'message' => $message,
            'recipients' => [array('recipient_id' => '1', 'dest_addr' => $phone)]
        );

        $Url = 'https://apisms.beem.africa/v1/send';

        $ch = curl_init($Url);
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt_array($ch, array(
            CURLOPT_POST => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => array(
                'Authorization:Basic ' . base64_encode("$api_key:$secret_key"),
                'Content-Type: application/json'
            ),
            CURLOPT_POSTFIELDS => json_encode($postData)
        ));

        $response = curl_exec($ch);
        
        if ($response === FALSE) {
            echo $response;

            die(curl_error($ch));
        }
        //var_dump($response);
    }

}
