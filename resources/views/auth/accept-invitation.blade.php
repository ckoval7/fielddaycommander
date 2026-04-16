<x-layouts.guest>
    <x-slot:title>Accept Invitation</x-slot:title>

    <div class="mb-6">
        <h2 class="text-2xl font-bold mb-2">Welcome, {{ $user->first_name }}!</h2>
        <p class="text-base-content/70">
            You've been invited to join FD Log DB. Please set your password to activate your account.
        </p>
        <div class="mt-4 p-4 bg-base-200 rounded-lg">
            <p class="text-sm"><strong>Call Sign:</strong> {{ $user->call_sign }}</p>
            <p class="text-sm"><strong>Email:</strong> {{ $user->email }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('invitation.accept', $token) }}">
        @csrf

        <div class="space-y-4">
            <x-input
                label="Password"
                type="password"
                name="password"
                required
                icon="phosphor-lock"
                hint="Choose a strong password for your account"
                errorField="password"
            />

            <x-input
                label="Confirm Password"
                type="password"
                name="password_confirmation"
                required
                icon="phosphor-lock"
                errorField="password_confirmation"
            />

            <div class="flex items-center justify-end pt-2">
                <x-button
                    label="Set Password & Login"
                    type="submit"
                    class="btn-primary"
                    icon="phosphor-check-circle"
                />
            </div>
        </div>
    </form>
</x-layouts.guest>
