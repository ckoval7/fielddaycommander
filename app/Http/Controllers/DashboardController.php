<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the main dashboard for authenticated users.
     *
     * Loads the user's default dashboard or creates one if it doesn't exist.
     */
    public function index(): View
    {
        $user = Auth::user();

        // Get or create user's default dashboard
        $dashboard = Dashboard::query()
            ->forUser($user)
            ->default()
            ->first();

        // If user doesn't have a default dashboard, create one
        if (! $dashboard) {
            $defaultConfig = config('dashboard.default_dashboards.user');

            $dashboard = Dashboard::create([
                'user_id' => $user->id,
                'title' => $defaultConfig['title'],
                'description' => $defaultConfig['description'],
                'layout_type' => $defaultConfig['layout_type'],
                'config' => $defaultConfig['widgets'],
                'is_default' => true,
            ]);
        }

        return view('dashboard.default', [
            'dashboard' => $dashboard,
            'widgets' => collect($dashboard->config),
        ]);
    }

    /**
     * Display the public TV dashboard.
     *
     * Loads static TV dashboard configuration from config file.
     * Handles optional kiosk mode via query parameter.
     */
    public function tv(Request $request): View
    {
        $tvConfig = config('dashboard.default_dashboards.tv');

        // Check if kiosk mode is requested
        $kioskMode = $request->boolean('kiosk', false);

        return view('dashboard.tv', [
            'title' => $tvConfig['title'],
            'description' => $tvConfig['description'],
            'layout_type' => $tvConfig['layout_type'],
            'widgets' => collect($tvConfig['widgets']),
            'kiosk' => $kioskMode,
        ]);
    }
}
