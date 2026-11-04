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
        abort_unless($user, 401);
        // A rider is whoever has a rider record: a shop owner can also ride, with the same Zinnvy account.
        $rider = Rider::where('user_id', $user->id)->first();
        abort_unless($rider, 403, 'Create your rider account first.');
        abort_if($rider->status === 'suspended', 403, 'Your rider account is paused. Contact Zinnvy.');
        $request->attributes->set('rider', $rider);

        return $next($request);
    }
}
