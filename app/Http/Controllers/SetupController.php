<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupStep1Request;
use App\Http\Requests\SetupStep2Request;
use App\Http\Requests\SetupStep3Request;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function complete(SetupStep3Request $request)
    {
        $step1 = $request->session()->get('setup_wizard.step1');
        $step2 = $request->session()->get('setup_wizard.step2');
        $step3 = $request->validated();

        // Verify all steps are complete
        if (! $step1 || ! $step2) {
            return redirect()->route('setup.welcome')
                ->with('error', 'Please complete all setup steps.');
        }

        DB::transaction(function () use ($step1, $step2, $step3) {
            // Create or update the system admin account
            $admin = User::updateOrCreate(
                ['call_sign' => User::SYSTEM_CALL_SIGN],
                [
                    'first_name' => 'System',
                    'last_name' => 'Administrator',
                    'email' => 'admin@localhost',
                    'password' => Hash::make($step1['admin_password']),
                    'user_role' => 'admin',
                    'email_verified_at' => now(),
                ]
            );

            // Ensure the admin has the Config Only role
            if (! $admin->hasRole('Config Only')) {
                $admin->assignRole('Config Only');
            }

            // Create default organization
            $organization = Organization::create([
                'name' => $step3['organization_name'],
                'callsign' => $step3['organization_callsign'] ?? null,
                'email' => $step3['organization_email'] ?? null,
                'phone' => $step3['organization_phone'] ?? null,
                'is_active' => true,
            ]);

            // Store organization ID as system setting
            Setting::set('default_organization_id', $organization->id);

            // Save site branding settings
            Setting::set('site_name', $step2['site_name']);
            if (! empty($step2['site_tagline'])) {
                Setting::set('site_tagline', $step2['site_tagline']);
            }

            // Move logo from temp to permanent storage
            if (! empty($step2['logo_temp_path'])) {
                $tempPath = $step2['logo_temp_path'];
                $extension = pathinfo(Storage::path($tempPath), PATHINFO_EXTENSION);
                $filename = 'logo-'.time().'.'.$extension;
                $permanentPath = 'branding/'.$filename;

                Storage::disk('public')->put(
                    $permanentPath,
                    Storage::get($tempPath)
                );

                Storage::delete($tempPath); // Clean up temp file
                Setting::set('site_logo_path', $permanentPath);
            }

            // Save system preferences
            Setting::set('timezone', $step3['timezone']);
            Setting::set('date_format', $step3['date_format']);
            Setting::set('time_format', $step3['time_format']);
            Setting::set('datetime_format', $step3['date_format'].' '.$step3['time_format']);

            if (! empty($step3['contact_email'])) {
                Setting::set('contact_email', $step3['contact_email']);
            }

            // Mark setup as complete
            Setting::set('setup_completed', 'true');
        });

        // Clear wizard session data
        $request->session()->forget('setup_wizard');

        return redirect()->route('login')->with('success', 'Setup completed! Please log in with your admin credentials.');
    }
}
