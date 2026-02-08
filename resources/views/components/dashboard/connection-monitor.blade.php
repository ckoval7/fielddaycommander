{{--
Connection Monitor Component

Detects WebSocket connection failures and displays status banner.
Automatically switches widgets to polling fallback mode.

Props:
- $tvMode: boolean - Whether this is TV dashboard (no banner)
--}}

@props(['tvMode' => false])

<div
    x-data="{
        connected: true,
        reconnecting: false,
        showBanner: false,
        dismissedAt: null,
        checkInterval: null,

        init() {
            this.checkConnection();

            // Check connection every 10 seconds
            this.checkInterval = setInterval(() => {
                this.checkConnection();
            }, 10000);

            // Listen for online/offline events
            window.addEventListener('online', () => this.handleOnline());
            window.addEventListener('offline', () => this.handleOffline());

            // Listen for Livewire connection events
            if (window.Livewire) {
                Livewire.on('reconnect', () => this.handleReconnect());
                Livewire.on('disconnect', () => this.handleDisconnect());
            }
        },

        destroy() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
            }
        },

        checkConnection() {
            // Check if Echo is available and connected
            if (window.Echo && window.Echo.connector && window.Echo.connector.socket) {
                const socket = window.Echo.connector.socket;

                if (socket.connected) {
                    if (!this.connected) {
                        this.handleReconnect();
                    }
                } else {
                    if (this.connected) {
                        this.handleDisconnect();
                    }
                }
            } else {
                // Echo not available, assume disconnected
                if (this.connected) {
                    this.handleDisconnect();
                }
            }
        },

        handleOnline() {
            this.reconnecting = true;
            this.checkConnection();
        },

        handleOffline() {
            this.handleDisconnect();
        },

        handleDisconnect() {
            this.connected = false;
            this.reconnecting = false;

            if (!@js($tvMode)) {
                this.showBanner = true;
                this.dismissedAt = null;
            }

            // Dispatch event to widgets to enable polling fallback
            window.dispatchEvent(new CustomEvent('connection-lost', {
                detail: { pollingRate: 10000 }
            }));
        },

        handleReconnect() {
            const wasDisconnected = !this.connected;
            this.connected = true;
            this.reconnecting = false;

            if (wasDisconnected) {
                // Dispatch event to widgets to restore real-time updates
                window.dispatchEvent(new CustomEvent('connection-restored'));

                if (!@js($tvMode)) {
                    // Show success banner
                    this.showBanner = true;

                    // Auto-dismiss after 3 seconds
                    setTimeout(() => {
                        this.showBanner = false;
                        this.dismissedAt = Date.now();
                    }, 3000);
                }
            }
        },

        dismissBanner() {
            this.showBanner = false;
            this.dismissedAt = Date.now();
        }
    }"
    x-show="showBanner && !@js($tvMode)"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 transform -translate-y-full"
    x-transition:enter-end="opacity-100 transform translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 transform translate-y-0"
    x-transition:leave-end="opacity-0 transform -translate-y-full"
    class="fixed top-0 left-0 right-0 z-50"
    role="alert"
    style="display: none;"
>
    {{-- Disconnected Banner --}}
    <div
        x-show="!connected"
        class="bg-warning text-warning-content px-4 py-3 shadow-lg"
    >
        <div class="container mx-auto flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-icon name="o-exclamation-triangle" class="w-5 h-5 flex-shrink-0" />
                <div class="flex-1">
                    <span class="font-semibold">Real-time updates paused</span>
                    <span class="hidden sm:inline"> - Reconnecting...</span>
                </div>
            </div>
            <button
                @click="dismissBanner()"
                class="btn btn-sm btn-ghost"
                aria-label="Dismiss"
            >
                <x-icon name="o-x-mark" class="w-4 h-4" />
            </button>
        </div>
    </div>

    {{-- Reconnected Banner --}}
    <div
        x-show="connected"
        class="bg-success text-success-content px-4 py-3 shadow-lg"
    >
        <div class="container mx-auto flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-icon name="o-check-circle" class="w-5 h-5 flex-shrink-0" />
                <div class="flex-1">
                    <span class="font-semibold">Real-time updates restored</span>
                </div>
            </div>
        </div>
    </div>
</div>
