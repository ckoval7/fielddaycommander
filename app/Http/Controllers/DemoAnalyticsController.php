<?php

namespace App\Http\Controllers;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class DemoAnalyticsController extends Controller
{
    public function beacon(Request $request): Response
    {
        abort_unless(config('demo.enabled'), 404);

        $cookie = $request->cookie('demo_session');
        [$uuid] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        if (! $uuid || ! Str::isUuid($uuid)) {
            return response('', 302, ['Location' => route('demo.landing')]);
        }

        $validated = $request->validate([
            'page' => 'required|string|max:500',
            'seconds' => 'required|integer|min:0|max:86400',
            'route' => 'nullable|string|max:100',
        ]);

        $session = DemoSession::where('session_uuid', $uuid)->first();

        if (! $session) {
            return response()->noContent();
        }

        DemoEvent::create([
            'demo_session_id' => $session->id,
            'type' => 'client',
            'name' => 'time_on_page',
            'route_name' => $validated['route'] ?? null,
            'metadata' => [
                'seconds' => $validated['seconds'],
                'page' => $validated['page'],
            ],
        ]);

        return response()->noContent();
    }
}
