<?php

namespace App\View\Components;

use App\Models\EventConfiguration;
use App\Models\Setting;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Component;

class AppBrand extends Component
{
    public ?EventConfiguration $activeEvent;

    public string $logoPath;

    public bool $hasCustomLogo = false;

    public string $callsign;

    public string $eventName;

    public ?string $tagline;

    public function __construct()
    {
        $siteName = Setting::get('site_name');
        $siteTagline = Setting::get('site_tagline');
        $siteLogoPath = Setting::get('site_logo_path');

        $this->activeEvent = EventConfiguration::where('is_active', true)->first();

        $this->logoPath = $this->resolveLogoPath($siteLogoPath);
        $this->callsign = $this->resolveCallsign($siteName);
        $this->eventName = $this->resolveEventName($siteName);
        $this->tagline = $this->resolveTagline($siteTagline);
    }

    /**
     * Resolve logo path: System settings > Event config > Default.
     */
    protected function resolveLogoPath(?string $siteLogoPath): string
    {
        if ($siteLogoPath && Storage::disk('public')->exists($siteLogoPath)) {
            $this->hasCustomLogo = true;

            return Storage::url($siteLogoPath);
        }

        if ($this->activeEvent && $this->activeEvent->logo_path) {
            $this->hasCustomLogo = true;

            return $this->activeEvent->logo_path;
        }

        $defaultLogo = config('branding.default_logo', '/images/logo.png');

        if (file_exists(public_path($defaultLogo))) {
            $this->hasCustomLogo = true;
        }

        return $defaultLogo;
    }

    /**
     * Resolve callsign/site name: System settings > Event callsign > Default.
     */
    protected function resolveCallsign(?string $siteName): string
    {
        return $siteName
            ?? $this->activeEvent?->callsign
            ?? config('branding.default_callsign', config('app.name'));
    }

    /**
     * Resolve event name: Active event > Site name > Default.
     */
    protected function resolveEventName(?string $siteName): string
    {
        if ($this->activeEvent) {
            return $this->activeEvent->event->name ?? ($siteName ?: config('app.name'));
        }

        return $siteName ?: config('app.name');
    }

    /**
     * Resolve tagline: System settings > Event tagline > Default.
     */
    protected function resolveTagline(?string $siteTagline): ?string
    {
        return $siteTagline
            ?? ($this->activeEvent?->tagline ?: null)
            ?? config('branding.default_tagline');
    }

    public function render(): View|Closure|string
    {
        return view('components.app-brand');
    }
}
