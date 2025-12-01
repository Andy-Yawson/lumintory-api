<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    public function show()
    {
        $tenant = Auth::user()->tenant;

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant found for user',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => $tenant->settings['currency'] ?? 'GHS',
                'currency_symbol' => $tenant->settings['currency_symbol'] ?? '₵',
            ],
        ]);
    }


    public function update(Request $request)
    {
        $tenant = Auth::user()->tenant;

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant found for user',
            ], 404);
        }

        $validated = $request->validate([
            'currency' => 'required|string|max:3',
        ]);

        $currency = strtoupper($validated['currency']);

        // simple map of currency -> symbol (you can expand this)
        $symbols = [
            'GHS' => '₵',
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'KES' => 'KSh',
            'ZAR' => 'R',
        ];

        $settings = $tenant->settings ?? [];
        $settings['currency'] = $currency;
        $settings['currency_symbol'] = $symbols[$currency] ?? $currency;

        $tenant->settings = $settings;
        $tenant->save();

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => [
                'currency' => $settings['currency'],
                'currency_symbol' => $settings['currency_symbol'],
            ],
        ]);
    }
}
