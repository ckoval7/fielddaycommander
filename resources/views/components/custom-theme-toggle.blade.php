<button
    x-data="{
        darkMode: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches),
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
    @click="toggle()"
    type="button"
    class="btn btn-circle btn-ghost btn-sm relative overflow-hidden group"
    :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
    {{ $attributes }}
>
    <!-- Sun icon (shown in dark mode) -->
    <div x-show="darkMode" x-transition class="absolute inset-0 flex items-center justify-center">
        <x-icon
            name="o-sun"
            class="w-5 h-5"
        />
    </div>

    <!-- Moon icon (shown in light mode) -->
    <div x-show="!darkMode" x-transition class="absolute inset-0 flex items-center justify-center">
        <x-icon
            name="o-moon"
            class="w-5 h-5"
        />
    </div>
</button>
