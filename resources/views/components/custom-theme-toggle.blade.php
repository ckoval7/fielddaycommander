<button
    x-data="{
        darkMode: false,
        toggle() {
            this.darkMode = !this.darkMode;
            const theme = this.darkMode ? 'dark' : 'light';
            localStorage.setItem('theme', theme);
            document.documentElement.setAttribute('data-theme', theme);
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
        },
        init() {
            // Sync component state with localStorage and document on initialization
            let theme = localStorage.getItem('theme');
            if (!theme) {
                theme = 'light';
                localStorage.setItem('theme', theme);
            }
            this.darkMode = theme === 'dark';
            document.documentElement.setAttribute('data-theme', theme);

            // Listen for theme changes from other components
            window.addEventListener('theme-changed', (e) => {
                this.darkMode = e.detail.theme === 'dark';
            });
        }
    }"
    @click="toggle()"
    type="button"
    class="btn btn-circle btn-ghost btn-sm relative overflow-hidden group"
    :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
    {{ $attributes }}
>
    <!-- Sun icon (shown in dark mode) -->
    <div x-show="darkMode" x-transition class="absolute inset-0 flex items-center justify-center">
        <x-icon
            name="phosphor-sun-duotone"
            class="w-5 h-5"
        />
    </div>

    <!-- Moon icon (shown in light mode) -->
    <div x-show="!darkMode" x-transition class="absolute inset-0 flex items-center justify-center">
        <x-icon
            name="phosphor-moon-duotone"
            class="w-5 h-5"
        />
    </div>
</button>
