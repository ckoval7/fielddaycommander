<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DemoController extends Controller
{
    public function landing(): \Illuminate\Contracts\View\View
    {
        abort_unless(config('demo.enabled'), 404);

        return view('demo.landing');
    }

    public function provision(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        return redirect()->route('demo.landing');
    }

    public function reset(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        return redirect('/');
    }
}
