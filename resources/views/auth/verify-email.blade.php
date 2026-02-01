<x-layouts.guest>
    <x-slot:title>Verify Email</x-slot:title>

    <div class="mb-4 text-sm">
        Thanks for signing up! Please verify your email address by clicking the link we emailed you.
    </div>

    @if (session('status') == 'verification-link-sent')
        <x-alert title="A new verification link has been sent!" class="mb-4" success />
    @endif

    <div class="space-y-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-button label="Resend Verification Email" type="submit" class="btn-primary w-full" icon="o-paper-airplane" />
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="link link-primary text-sm">
                Log Out
            </button>
        </form>
    </div>
</x-layouts.guest>
