<x-layouts.guest>
    <x-slot:title>Confirm Password</x-slot:title>

    <div class="mb-4 text-sm">
        This is a secure area. Please confirm your password before continuing.
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="space-y-4">
            <x-input label="Password" type="password" name="password" required icon="phosphor-lock" errorField="password" />

            <div class="pt-2">
                <x-button label="Confirm" type="submit" class="btn-primary w-full" icon="phosphor-check" />
            </div>
        </div>
    </form>
</x-layouts.guest>
