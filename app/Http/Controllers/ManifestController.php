<?php

namespace App\Http\Controllers;

use App\Models\StoreSetting;
use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    /**
     * Runtime web app manifest: identity comes from StoreSetting so the same
     * built frontend serves every instance without a per-customer source edit.
     * Icons stay generic/static — dynamic per-store icon generation is out of
     * scope (see scripts/generate-pwa-icons.py for the offline provisioning path).
     */
    public function show(): JsonResponse
    {
        $settings = StoreSetting::current();

        return response()->json([
            'name' => $settings->displayName(),
            'short_name' => $settings->shortDisplayName(),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $settings->brandPrimary(),
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ])
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
