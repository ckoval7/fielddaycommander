<x-layouts.guest>
    <x-slot:title>Log In</x-slot:title>

    @if (session('status'))
        <x-alert title="{{ session('status') }}" class="mb-4" />
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="space-y-4">
            <x-input label="Email" type="email" name="email" :value="old('email')" required autofocus icon="o-envelope" errorField="email" />
            <x-input label="Password" type="password" name="password" required icon="o-lock-closed" errorField="password" />
            <x-checkbox label="Remember me" name="remember" />

            <div class="flex items-center justify-between pt-2">
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link link-primary text-sm">
                        Forgot password?
                    </a>
                @endif

                <x-button label="Log In" type="submit" class="btn-primary" icon="o-arrow-right-end-on-rectangle" />
            </div>

            @if (config('auth-security.registration_mode') !== 'disabled')
                <div class="text-center pt-4 border-t">
                    <span class="text-sm">Need an account?</span>
                    <a href="{{ route('register') }}" class="link link-primary text-sm ml-1">Register</a>
                </div>
            @endif
        </div>
    </form>
</x-layouts.guest>
