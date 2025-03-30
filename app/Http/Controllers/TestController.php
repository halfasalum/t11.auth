<?php

namespace App\Http\Controllers;
use App\Services\RabbitMQProducer;

use Illuminate\Http\Request;

class TestController extends Controller
{
    protected $rabbitMQProducer;

    public function __construct(RabbitMQProducer $rabbitMQProducer)
    {
        $this->rabbitMQProducer = $rabbitMQProducer;
    }

    public function sendMessage()
    {
        $this->rabbitMQProducer->sendMessage("Hello RabbitMQ!");
        return response()->json(['status' => 'Message sent']);
    }
    public function home(){
        return response()->json([
            'page'     => 'home'
        ]);
    }

    public function about(){
        return response()->json([
            'page'     => 'about'
        ]);
    }


    public function contact(){
        return response()->json([
            'page'     => 'contact'
        ]);
    }
}
