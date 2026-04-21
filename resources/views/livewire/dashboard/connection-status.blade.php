<div
    x-data="{
        connected: true,
        connecting: false,
        disconnectedAt: null,
        lastUpdateAt: Date.now(),
        offlineTimeout: 5000, // 5 seconds
        autoRefreshInterval: {{ $isTvMode ? 60000 : 0 }}, // 1 minute for TV mode
        showOfflineBanner: false,

        pusherConnection: null,
        pusherHandlers: {},
        qsoLoggedHandler: null,
        checkIntervalId: null,

        init() {
            // Listen for Reverb/Echo connection events
            if (window.Echo) {
                this.pusherConnection = window.Echo.connector.pusher.connection;

                this.pusherHandlers = {
                    connected: () => this.handleConnected(),
                    disconnected: () => this.handleDisconnected(),
                    connecting: () => { this.connecting = true; },
                    unavailable: () => this.handleDisconnected(),
                    failed: () => this.handleDisconnected(),
                };

                Object.entries(this.pusherHandlers).forEach(([event, handler]) => {
                    this.pusherConnection.bind(event, handler);
                });

                // Check initial connection state
                const state = this.pusherConnection.state;
                this.connected = (state === 'connected');
                this.connecting = (state === 'connecting');

                if (!this.connected && !this.connecting) {
                    this.disconnectedAt = Date.now();
                    this.checkOfflineStatus();
                }
            }

            // Listen for data updates to track last update time
            this.qsoLoggedHandler = () => {
                this.lastUpdateAt = Date.now();
            };
            window.addEventListener('qso-logged', this.qsoLoggedHandler);

            // TV mode: Auto-refresh if no updates received
            if (this.autoRefreshInterval > 0) {
                this.checkIntervalId = setInterval(() => {
                    this.checkAutoRefresh();
                }, 10000); // Check every 10 seconds
            }
        },

        destroy() {
            if (this.pusherConnection && this.pusherHandlers) {
                Object.entries(this.pusherHandlers).forEach(([event, handler]) => {
                    this.pusherConnection.unbind(event, handler);
                });
            }
            this.pusherConnection = null;
            this.pusherHandlers = {};

            if (this.qsoLoggedHandler) {
                window.removeEventListener('qso-logged', this.qsoLoggedHandler);
                this.qsoLoggedHandler = null;
            }

            if (this.checkIntervalId) {
                clearInterval(this.checkIntervalId);
                this.checkIntervalId = null;
            }
        },

        handleConnected() {
            this.connected = true;
            this.connecting = false;
            this.disconnectedAt = null;
            this.showOfflineBanner = false;
            this.lastUpdateAt = Date.now();
        },

        handleDisconnected() {
            this.connected = false;
            this.connecting = false;
            if (!this.disconnectedAt) {
                this.disconnectedAt = Date.now();
                this.checkOfflineStatus();
            }
        },

        checkOfflineStatus() {
            setTimeout(() => {
                if (this.disconnectedAt && (Date.now() - this.disconnectedAt >= this.offlineTimeout)) {
                    this.showOfflineBanner = true;
                }
            }, this.offlineTimeout);
        },

        checkAutoRefresh() {
            if (this.autoRefreshInterval === 0) return;

            const timeSinceLastUpdate = Date.now() - this.lastUpdateAt;
            if (timeSinceLastUpdate >= this.autoRefreshInterval && !this.connected) {
                console.log('Auto-refresh triggered: No updates for', timeSinceLastUpdate, 'ms');
                window.location.reload();
            }
        },

        dismissBanner() {
            this.showOfflineBanner = false;
        }
    }"
>
    {{-- Offline Warning Banner --}}
    <div
        x-show="showOfflineBanner"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="fixed top-0 left-0 right-0 z-50 bg-warning text-warning-content shadow-lg"
        role="alert"
        aria-live="assertive"
    >
        <div class="container mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-icon name="phosphor-warning" class="w-6 h-6 flex-shrink-0" />
                <div>
                    <div class="font-semibold">Connection Lost</div>
                    <div class="text-sm opacity-90">
                        Real-time updates are unavailable. Attempting to reconnect...
                    </div>
                </div>
            </div>
            <button
                @click="dismissBanner"
                type="button"
                class="btn btn-ghost btn-sm btn-circle"
                aria-label="Dismiss banner"
            >
                <x-icon name="phosphor-x" class="w-5 h-5" />
            </button>
        </div>
    </div>

    {{-- Connection Status Badge (Bottom Right) --}}
    @if($showBadge)
        <output
            class="fixed bottom-4 right-4 z-40 block"
            aria-live="polite"
        >
            <div
                x-show="!connected"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="badge gap-2 {{ $isTvMode ? 'badge-lg text-lg py-3 px-4' : 'badge-md' }}"
                :class="{
                    'badge-warning': connecting,
                    'badge-error': !connecting && !connected
                }"
            >
                <span
                    class="relative flex h-2 w-2"
                    x-show="connecting"
                >
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-warning-content opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-warning-content"></span>
                </span>
                <span
                    class="relative flex h-2 w-2"
                    x-show="!connecting"
                >
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-error-content"></span>
                </span>
                <span x-text="connecting ? 'Connecting...' : 'Offline'"></span>
            </div>

            <div
                x-show="connected"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="badge badge-success gap-2 {{ $isTvMode ? 'badge-lg text-lg py-3 px-4' : 'badge-md' }}"
            >
                <span class="relative flex h-2 w-2">
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-success-content"></span>
                </span>
                <span>Connected</span>
            </div>
        </output>
    @endif
</div>
