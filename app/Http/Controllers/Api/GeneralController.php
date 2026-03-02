<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class GeneralController extends Controller
{
    public function sendEmail(Request $request)
    {
        $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string',
            'body' => 'required|string',
            'host' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'from' => 'required|email',
            'port' => 'nullable|integer',
        ]);

        $to = $request->to;
        $subject = $request->subject;
        $body = $request->body;


        // 1. Override the config values
        Config::set('mail.mailers.smtp.host', $request->host);
        Config::set('mail.mailers.smtp.port', $request->port ?? 587);
        Config::set('mail.mailers.smtp.username', $request->username);
        Config::set('mail.mailers.smtp.password', $request->password);
        Config::set('mail.from.address', $request->from);


        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return response()->json([
            'message' => 'Email sent successfully',
        ]);
    }
}
