<div x-data="{
    darkMode: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches),
    toggle() {
        this.darkMode = !this.darkMode;
        const theme = this.darkMode ? 'dark' : 'light';
        localStorage.setItem('theme', theme);
        document.documentElement.setAttribute('data-theme', theme);
    }
}" x-init="document.documentElement.setAttribute('data-theme', darkMode ? 'dark' : 'light')" {{ $attributes }}>
    <button
        @click="toggle()"
        type="button"
        class="btn btn-circle btn-ghost btn-sm relative overflow-hidden group"
        :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
    >
        <!-- Sun icon (shown in dark mode) -->
        <x-icon
            name="o-sun"
            class="w-5 h-5 absolute inset-0 m-auto"
            x-show="darkMode"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 rotate-90 scale-0"
            x-transition:enter-end="opacity-100 rotate-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 rotate-0 scale-100"
            x-transition:leave-end="opacity-0 rotate-90 scale-0"
        />

        <!-- Moon icon (shown in light mode) -->
        <x-icon
            name="o-moon"
            class="w-5 h-5 absolute inset-0 m-auto"
            x-show="!darkMode"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -rotate-90 scale-0"
            x-transition:enter-end="opacity-100 rotate-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 rotate-0 scale-100"
            x-transition:leave-end="opacity-0 -rotate-90 scale-0"
        />
    </button>
</div>
