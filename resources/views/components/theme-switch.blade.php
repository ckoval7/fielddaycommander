@props(['compact' => false, 'as' => 'div'])

{{-- Light/dark toggle backed by localStorage "theme"; instances stay in sync via the theme-changed event. --}}
<{{ $as }}
    {{ $attributes }}
    x-data="{
        darkMode: (localStorage.getItem('theme') || 'light') === 'dark',
        toggle() {
            this.darkMode = !this.darkMode;
            const theme = this.darkMode ? 'dark' : 'light';
            localStorage.setItem('theme', theme);
            document.documentElement.setAttribute('data-theme', theme);
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
        },
        init() {
            window.addEventListener('theme-changed', (e) => {
                this.darkMode = e.detail.theme === 'dark';
            });
        }
    }"
>
    <button
        type="button"
        @click="toggle()"
        :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
        @if($compact) class="btn btn-ghost btn-circle btn-sm sm:btn-md" :title="darkMode ? 'Light Mode' : 'Dark Mode'" @endif
    >
        <x-icon name="phosphor-sun-duotone" class="w-4 h-4" x-show="darkMode" />
        <x-icon name="phosphor-moon-duotone" class="w-4 h-4" x-show="!darkMode" />
        @unless($compact)
            <span x-text="darkMode ? 'Light Mode' : 'Dark Mode'">Theme</span>
        @endunless
    </button>
</{{ $as }}>
