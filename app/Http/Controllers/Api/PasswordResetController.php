<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Carbon\Carbon;
use DB;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function requestReset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $plainToken = Str::random(64);

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => $plainToken,
                'created_at' => now(),
            ]);

            $resetUrl = env('FRONTEND_URL') . '/reset-password?token=' . $plainToken . '&email=' . urlencode($user->email);

            // Mail::to($user->email)->send(new PasswordResetMail($user, $resetUrl));
            return response()->json(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
        }

        return response()->json([
            'message' => 'If that email exists, a reset link has been sent.',
            'success' => false,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
                'success' => true,
            ], 422);
        }

        // Token TTL: 60 minutes (tweak if needed)
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return response()->json([
                'message' => 'Reset token has expired.',
                'success' => false
            ], 422);
        }

        if ($data['token'] != $record->token) {
            return response()->json([
                'message' => 'Invalid reset token.',
                'success' => false,
            ], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
                'success' => false
            ], 404);
        }

        $user->password = Hash::make($data['password']);
        $user->first_login = false;
        $user->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json([
            'message' => 'Password reset successful. You can now log in.',
            'success' => true,
        ]);
    }
}
