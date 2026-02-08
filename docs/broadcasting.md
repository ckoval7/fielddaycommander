# Laravel Echo & Broadcasting Setup

Laravel Echo is now configured and ready to use for real-time updates in the dashboard.

## Starting the Reverb Server

The Reverb WebSocket server needs to be running for broadcasting to work:

```bash
php artisan reverb:start
```

Or run it in debug mode to see connection logs:

```bash
php artisan reverb:start --debug
```

For development, you may want to run both your dev server and Reverb together. You can use:

```bash
composer run dev
```

This will start both the Laravel development server and Reverb concurrently.

## Configuration

All configuration is in `.env`:

- `BROADCAST_CONNECTION=reverb` - Broadcasting driver
- `REVERB_APP_KEY` - Application key for Reverb
- `REVERB_HOST=localhost` - Reverb server host
- `REVERB_PORT=8080` - Reverb WebSocket port
- `REVERB_SCHEME=http` - Use `https` for production

The Vite variables are automatically set from the Reverb config.

## Broadcasting Events

### Creating a Broadcast Event

Events that implement `ShouldBroadcast` will be automatically broadcast:

```php
use App\Events\Dashboard\DashboardUpdated;

// Broadcast an event
DashboardUpdated::dispatch('New contact logged', [
    'contact_id' => 123,
    'callsign' => 'W1AW',
]);
```

### Example Event

See `app/Events/Dashboard/DashboardUpdated.php` for a complete example:

```php
<?php

namespace App\Events\Dashboard;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class DashboardUpdated implements ShouldBroadcast
{
    public function __construct(
        public string $message,
        public array $data = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('dashboard'),
        ];
    }
}
```

## Listening for Events in JavaScript

Laravel Echo is automatically initialized in `resources/js/bootstrap.js` when Reverb is configured.

### In Blade Templates

```html
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.Echo) {
        // Listen for dashboard updates
        window.Echo.channel('dashboard')
            .listen('DashboardUpdated', (e) => {
                console.log('Dashboard updated:', e.message, e.data);

                // Update your UI here
                // For example, refresh a Livewire component:
                // Livewire.dispatch('refresh');
            });
    }
});
</script>
```

### In Alpine.js Components

```html
<div x-data="dashboardListener">
    <div x-text="lastUpdate"></div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardListener', () => ({
        lastUpdate: 'No updates yet',

        init() {
            if (window.Echo) {
                window.Echo.channel('dashboard')
                    .listen('DashboardUpdated', (e) => {
                        this.lastUpdate = e.message;
                        console.log('Update received:', e);
                    });
            }
        }
    }));
});
</script>
```

### In Livewire Components

Livewire has built-in support for Echo. Add this to your component class:

```php
use Livewire\Attributes\On;

class DashboardManager extends Component
{
    #[On('echo:dashboard,DashboardUpdated')]
    public function handleDashboardUpdate($event): void
    {
        // Handle the broadcast event
        $this->dispatch('refresh');
    }
}
```

Or listen in the view:

```blade
<div wire:poll.5s>
    @script
    <script>
        $wire.on('echo:dashboard,DashboardUpdated', (event) => {
            console.log('Dashboard updated:', event);
            $wire.$refresh();
        });
    </script>
    @endscript
</div>
```

## Channel Types

### Public Channels

Anyone can listen to public channels:

```php
public function broadcastOn(): array
{
    return [new Channel('dashboard')];
}
```

```javascript
Echo.channel('dashboard').listen('DashboardUpdated', (e) => {
    console.log(e);
});
```

### Private Channels

Require authentication to listen:

```php
public function broadcastOn(): array
{
    return [new PrivateChannel('dashboard.user.' . $this->user->id)];
}
```

Define authorization in `routes/channels.php`:

```php
Broadcast::channel('dashboard.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

```javascript
Echo.private('dashboard.user.123').listen('DashboardUpdated', (e) => {
    console.log(e);
});
```

### Presence Channels

Track who is online:

```php
public function broadcastOn(): array
{
    return [new PresenceChannel('dashboard.event.' . $this->eventId)];
}
```

```javascript
Echo.join('dashboard.event.1')
    .here((users) => {
        console.log('Currently online:', users);
    })
    .joining((user) => {
        console.log('User joined:', user);
    })
    .leaving((user) => {
        console.log('User left:', user);
    })
    .listen('DashboardUpdated', (e) => {
        console.log('Update:', e);
    });
```

## Common Use Cases

### Real-time Contact Log Updates

```php
// When a new contact is logged
DashboardUpdated::dispatch('New contact logged', [
    'contact' => $contact->toArray(),
    'stats' => $event->getUpdatedStats(),
]);
```

### Station Status Changes

```php
// When a station goes online/offline
DashboardUpdated::dispatch('Station status changed', [
    'station_id' => $station->id,
    'status' => 'online',
]);
```

### Score Updates

```php
// When the score changes
DashboardUpdated::dispatch('Score updated', [
    'total_score' => $event->calculateTotalScore(),
    'contact_count' => $event->contacts_count,
]);
```

## Debugging

### Check if Echo is initialized

```javascript
console.log('Echo available:', !!window.Echo);
```

### Monitor Reverb server logs

```bash
php artisan reverb:start --debug
```

### Check browser console

Open browser DevTools and look for WebSocket connection messages. You should see:
- Connection to `ws://localhost:8080`
- Subscription messages when channels are joined
- Event messages when broadcasts are received

## Queue Workers

Broadcast events are typically queued. Make sure your queue worker is running:

```bash
php artisan queue:work
```

Or for development with auto-reload:

```bash
php artisan queue:listen
```

## Production Considerations

1. **Use HTTPS/WSS**: Set `REVERB_SCHEME=https` in production
2. **Configure allowed origins**: Update `config/reverb.php` to restrict `allowed_origins`
3. **Use Redis for scaling**: Enable Redis scaling in `config/reverb.php` for multi-server setups
4. **Run Reverb as a service**: Use systemd, supervisor, or Docker to keep Reverb running
5. **Consider Laravel Forge/Vapor**: These handle Reverb deployment automatically

## Resources

- [Laravel Broadcasting Docs](https://laravel.com/docs/broadcasting)
- [Laravel Reverb Docs](https://laravel.com/docs/reverb)
- [Laravel Echo Docs](https://github.com/laravel/echo)
