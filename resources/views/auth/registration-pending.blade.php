<x-layouts.guest>
    <x-slot:title>Registration Pending</x-slot:title>

    <div class="text-center space-y-4">
        <div class="flex justify-center">
            <x-icon name="o-clock" class="w-16 h-16 text-warning" />
        </div>

        <h2 class="text-xl font-semibold">Account Pending Approval</h2>

        <p class="text-sm text-base-content/70">
            Your account has been created and is pending administrator approval.
            You will be able to log in once an administrator has activated your account.
        </p>

        <div class="pt-4 border-t">
            <a href="{{ route('login') }}" class="link link-primary text-sm">
                Return to login
            </a>
        </div>
    </div>
</x-layouts.guest>
