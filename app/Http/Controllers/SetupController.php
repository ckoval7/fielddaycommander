<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Http\Requests\SetupStep1Request;
use App\Http\Requests\SetupStep2Request;
use App\Http\Requests\SetupStep3Request;
use App\Services\InitialSetupService;
use App\Support\SystemAccount;
use Illuminate\Support\Facades\Storage;

class SetupController extends Controller
{
    public function welcome()
    {
        return view('setup.welcome', ['step' => 1]);
    }

    public function stepOne(SetupStep1Request $request)
    {
        // Store step 1 data in session
        $request->session()->put('setup_wizard.step1', [
            'admin_password' => $request->input('admin_password'),
        ]);

        return redirect()->route('setup.branding');
    }

    public function branding()
    {
        return view('setup.branding', ['step' => 2]);
    }

    public function stepTwo(SetupStep2Request $request)
    {
        $data = [
            'site_name' => $request->input('site_name'),
            'site_tagline' => $request->input('site_tagline'),
        ];

        // Handle logo upload temporarily (save to session, final save on completion)
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('temp', 'local');
            $data['logo_temp_path'] = $logoPath;
        }

        $request->session()->put('setup_wizard.step2', $data);

        return redirect()->route('setup.preferences');
    }

    public function preferences()
    {
        return view('setup.preferences', ['step' => 3]);
    }

    public function complete(SetupStep3Request $request, InitialSetupService $setup)
    {
        $step1 = $request->session()->get('setup_wizard.step1');
        $step2 = $request->session()->get('setup_wizard.step2');
        $step3 = $request->validated();

        // Verify all steps are complete
        if (! $step1 || ! $step2) {
            return redirect()->route('setup.welcome')
                ->with('error', 'Setup details are missing. Please start again with the admin password.');
        }

        $setup->complete([
            'system_password' => $step1['admin_password'],
            'organization' => [
                'name' => $step3['organization_name'],
                'callsign' => $step3['organization_callsign'] ?? null,
                'email' => $step3['organization_email'] ?? null,
                'phone' => $step3['organization_phone'] ?? null,
            ],
            'site_name' => $step2['site_name'],
            'site_tagline' => $step2['site_tagline'] ?? null,
            'site_logo_path' => $this->storeLogo($step2['logo_temp_path'] ?? null),
            'timezone' => $step3['timezone'],
            'date_format' => $step3['date_format'],
            'time_format' => $step3['time_format'],
            'contact_email' => $step3['contact_email'] ?? null,
        ], method: 'setup wizard');

        // Clear wizard session data
        $request->session()->forget('setup_wizard');

        if (SystemAccount::isLocked()) {
            return redirect()->route('login')->with('success', 'Setup is complete. SYSTEM is locked, so open it with php artisan user:sign-in-link on the server.');
        }

        return redirect()->route('login')->with('success', 'Setup is complete! Please sign in with admin@localhost and the password you set.');
    }

    /**
     * Move the logo uploaded in step 2 from temporary to public storage.
     *
     * @return string|null The logo's path on the public disk.
     */
    private function storeLogo(?string $tempPath): ?string
    {
        if (blank($tempPath)) {
            return null;
        }

        $extension = pathinfo(Storage::path($tempPath), PATHINFO_EXTENSION);
        $permanentPath = 'branding/logo-'.time().'.'.$extension;

        Storage::disk('public')->put($permanentPath, Storage::get($tempPath));
        Storage::delete($tempPath);

        return $permanentPath;
    }
}
