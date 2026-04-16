<x-layouts.guest>
    <x-slot:title>Two-Factor Authentication</x-slot:title>

    <div x-data="{ recovery: false }">
        <div class="mb-4 text-sm text-base-content/70">
            <p x-show="! recovery">
                Please enter the authentication code from your authenticator app.
            </p>
            <p x-show="recovery" x-cloak>
                Please enter one of your emergency recovery codes.
            </p>
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf

            <div class="space-y-4">
                <div x-show="! recovery">
                    <x-input label="Code" name="code" inputmode="numeric" autofocus autocomplete="one-time-code" errorField="code" />
                </div>

                <div x-show="recovery" x-cloak>
                    <x-input label="Recovery Code" name="recovery_code" autocomplete="one-time-code" errorField="recovery_code" />
                </div>

                <div class="flex items-center justify-between pt-2">
                    <button type="button" class="link link-primary text-sm" x-show="! recovery" x-on:click.prevent="recovery = true">
                        Use a recovery code
                    </button>
                    <button type="button" class="link link-primary text-sm" x-show="recovery" x-cloak x-on:click.prevent="recovery = false">
                        Use an authentication code
                    </button>

                    <x-button label="Log In" type="submit" class="btn-primary" icon="phosphor-sign-in" />
                </div>
            </div>
        </form>
    </div>
</x-layouts.guest>
