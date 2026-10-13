<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A workspace's brand: its colour and logo replace Zinnvy's in the app and on receipts.
 * Stored in tenants.settings (brand_color, logo_path); the logo lives on the media disk (R2).
 */
class BrandController extends Controller
{
    public function show()
    {
        return response()->json(Auth::user()->tenant->brand);
    }

    public function update(Request $request)
    {
        $tenant = $this->adminTenant();
        $data = $request->validate(['color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $color = $data['color'] ?? null;

        // White text sits on the brand colour (buttons, sidebar highlights), so it must stay readable.
        if ($color && self::contrastWithWhite($color) < 3) {
            return response()->json([
                'message' => 'That colour is too light for white text on buttons. Choose a darker shade.',
                'errors' => ['color' => ['That colour is too light for white text on buttons. Choose a darker shade.']],
            ], 422);
        }

        $this->saveSettings($tenant, ['brand_color' => $color ? strtolower($color) : null]);

        return response()->json($tenant->fresh()->brand);
    }

    public function uploadLogo(Request $request)
    {
        $tenant = $this->adminTenant();
        // No SVG: it can carry scripts, and the receipt PDF renderer can't draw it reliably.
        $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048']);

        $file = $request->file('logo');
        $path = "tenants/{$tenant->id}/brand/logo-".Str::uuid().'.'.$file->extension();

        try {
            Storage::disk('media')->put($path, $file->getContent(), ['ContentType' => $file->getMimeType()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Logo storage is unavailable right now. Please try again shortly.'], 503);
        }

        $old = $tenant->settings['logo_path'] ?? null;
        $this->saveSettings($tenant, ['logo_path' => $path]);
        if ($old) {
            rescue(fn () => Storage::disk('media')->delete($old), report: true);
        }

        return response()->json($tenant->fresh()->brand);
    }

    public function removeLogo()
    {
        $tenant = $this->adminTenant();
        $old = $tenant->settings['logo_path'] ?? null;
        $this->saveSettings($tenant, ['logo_path' => null]);
        if ($old) {
            rescue(fn () => Storage::disk('media')->delete($old), report: true);
        }

        return response()->json($tenant->fresh()->brand);
    }

    /** WCAG contrast ratio of a #rrggbb colour against white. */
    public static function contrastWithWhite(string $hex): float
    {
        $lum = 0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $w) {
            $c = hexdec(substr($hex, 1 + $i * 2, 2)) / 255;
            $lum += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return 1.05 / ($lum + 0.05);
    }

    private function adminTenant(): Tenant
    {
        abort_unless(Auth::user()->role === 'Administrator', 403, 'Only a workspace Administrator can change the brand.');

        return Auth::user()->tenant;
    }

    private function saveSettings(Tenant $tenant, array $values): void
    {
        $settings = $tenant->settings ?? [];
        foreach ($values as $k => $v) {
            if ($v === null) {
                unset($settings[$k]);
            } else {
                $settings[$k] = $v;
            }
        }
        $tenant->settings = $settings;
        $tenant->save();
    }
}
