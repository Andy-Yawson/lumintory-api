<?php

namespace App\Http\Middleware;

use App\Models\Dispatch\Rider;
use Closure;
use Illuminate\Http\Request;

/** Zinnvy riders only. Puts their rider record on the request. */
class EnsureRider
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && strtolower((string) $user->role) === 'rider', 403, 'This area is for Zinnvy riders.');
        $rider = Rider::where('user_id', $user->id)->first();
        abort_unless($rider && $rider->status !== 'suspended' && $rider->status !== 'rejected', 403, 'Your rider account is not active. Contact Zinnvy.');
        $request->attributes->set('rider', $rider);

        return $next($request);
    }
}
