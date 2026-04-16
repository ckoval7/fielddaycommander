<x-layouts.guest>
    <x-slot:title>Forgot Password</x-slot:title>

    <div class="mb-4 text-sm">
        Forgot your password? Enter your email and we'll send you a password reset link.
    </div>

    @if (session('status'))
        <x-alert title="{{ session('status') }}" class="mb-4" success />
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="space-y-4">
            <x-input label="Email" type="email" name="email" :value="old('email')" required autofocus icon="phosphor-envelope" errorField="email" />

            <div class="flex items-center justify-between pt-2">
                <a href="{{ route('login') }}" class="link link-primary text-sm">
                    Back to login
                </a>

                <x-button label="Email Reset Link" type="submit" class="btn-primary" icon="phosphor-paper-plane-tilt" />
            </div>
        </div>
    </form>
</x-layouts.guest>
