@auth
<div class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="btn btn-ghost btn-circle avatar">
        <div class="w-10 rounded-full">
            @if(auth()->user()->avatar_path && file_exists(public_path(auth()->user()->avatar_path)))
                <img src="{{ asset(auth()->user()->avatar_path) }}" alt="{{ auth()->user()->call_sign }}" class="rounded-full">
            @else
                <div class="avatar placeholder">
                    <div class="bg-slate-600 text-white rounded-full w-10 flex items-center justify-center">
                        <span class="text-sm">{{ auth()->user()->getInitials() }}</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[100] p-2 shadow-lg bg-base-100 rounded-box w-64 border border-base-300">
        <!-- User info header -->
        <li class="menu-title px-3 py-2">
            <div class="flex flex-col gap-1">
                <span class="text-base font-bold">{{ auth()->user()->call_sign }}</span>
                @if(auth()->user()->name)
                    <span class="text-xs opacity-60">{{ auth()->user()->name }}</span>
                @endif
                @if(auth()->user()->license_class)
                    <span class="text-xs opacity-50">{{ auth()->user()->license_class }}</span>
                @endif
            </div>
        </li>

        <div class="divider my-1"></div>

        <!-- Menu items -->
        <li>
            <a href="{{ route('profile') }}" wire:navigate>
                <x-icon name="o-user" class="w-4 h-4" />
                Profile
            </a>
        </li>

        @can('manage-users')
            <div class="divider my-1"></div>

            <li>
                <a href="{{ route('users.index') }}" wire:navigate>
                    <x-icon name="o-user-group" class="w-4 h-4" />
                    Manage Users
                </a>
            </li>
        @endcan

        @can('manage-settings')
            <li>
                <a href="{{ route('settings.index') }}" wire:navigate>
                    <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                    System Settings
                </a>
            </li>
        @endcan

        <div class="divider my-1"></div>

        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full text-start text-error flex items-center gap-2">
                    <x-icon name="o-arrow-right-on-rectangle" class="w-4 h-4" />
                    Logout
                </button>
            </form>
        </li>
    </ul>
</div>
@else
<a href="{{ route('login') }}" wire:navigate class="btn btn-primary">
    Login
</a>
@endauth
