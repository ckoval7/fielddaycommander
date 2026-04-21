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
        hasEverConnected: false,
        onlineHandler: null,
        offlineHandler: null,
        livewireReconnectUnsub: null,
        livewireDisconnectUnsub: null,

        init() {
            // Delay the first check to give Echo time to establish its connection
            setTimeout(() => {
                this.checkConnection();

                // Check connection every 10 seconds
                this.checkInterval = setInterval(() => {
                    this.checkConnection();
                }, 10000);
            }, 5000);

            // Listen for online/offline events
            this.onlineHandler = () => this.handleOnline();
            this.offlineHandler = () => this.handleOffline();
            window.addEventListener('online', this.onlineHandler);
            window.addEventListener('offline', this.offlineHandler);

            // Listen for Livewire connection events
            if (window.Livewire) {
                this.livewireReconnectUnsub = Livewire.on('reconnect', () => this.handleReconnect());
                this.livewireDisconnectUnsub = Livewire.on('disconnect', () => this.handleDisconnect());
            }
        },

        destroy() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }

            if (this.onlineHandler) {
                window.removeEventListener('online', this.onlineHandler);
                this.onlineHandler = null;
            }
            if (this.offlineHandler) {
                window.removeEventListener('offline', this.offlineHandler);
                this.offlineHandler = null;
            }

            if (typeof this.livewireReconnectUnsub === 'function') {
                this.livewireReconnectUnsub();
                this.livewireReconnectUnsub = null;
            }
            if (typeof this.livewireDisconnectUnsub === 'function') {
                this.livewireDisconnectUnsub();
                this.livewireDisconnectUnsub = null;
            }
        },

        checkConnection() {
            // Check if Echo is available and connected
            if (window.Echo && window.Echo.connector) {
                // Check for Pusher (Reverb) connection
                if (window.Echo.connector.pusher && window.Echo.connector.pusher.connection) {
                    const state = window.Echo.connector.pusher.connection.state;

                    if (state === 'connected') {
                        this.hasEverConnected = true;
                        if (!this.connected) {
                            this.handleReconnect();
                        }
                    } else {
                        if (this.connected) {
                            this.handleDisconnect();
                        }
                    }
                }
                // Check for Socket.io connection (fallback)
                else if (window.Echo.connector.socket) {
                    const socket = window.Echo.connector.socket;

                    if (socket.connected) {
                        this.hasEverConnected = true;
                        if (!this.connected) {
                            this.handleReconnect();
                        }
                    } else {
                        if (this.connected) {
                            this.handleDisconnect();
                        }
                    }
                } else {
                    // Echo connector exists but no connection found
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

            // Only show the banner if we previously had a connection
            if (!@js($tvMode) && this.hasEverConnected) {
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
            this.hasEverConnected = true;

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
                <x-icon name="phosphor-warning" class="w-5 h-5 flex-shrink-0" />
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
                <x-icon name="phosphor-x" class="w-4 h-4" />
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
                <x-icon name="phosphor-check-circle" class="w-5 h-5 flex-shrink-0" />
                <div class="flex-1">
                    <span class="font-semibold">Real-time updates restored</span>
                </div>
            </div>
        </div>
    </div>
</div>
