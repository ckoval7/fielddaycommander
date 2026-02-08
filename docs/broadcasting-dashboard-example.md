# Dashboard Broadcasting Example

This document shows a complete example of adding real-time updates to the dashboard.

## Step 1: Create the Broadcast Event

Already created: `app/Events/Dashboard/DashboardUpdated.php`

## Step 2: Broadcast the Event

When a contact is logged, broadcast an update:

```php
use App\Events\Dashboard\DashboardUpdated;

// In your contact logging code
public function logContact(array $data): Contact
{
    $contact = Contact::create($data);

    // Broadcast the update
    DashboardUpdated::dispatch('New contact logged', [
        'contact_id' => $contact->id,
        'callsign' => $contact->callsign,
        'band' => $contact->band,
        'mode' => $contact->mode,
    ]);

    return $contact;
}
```

## Step 3: Listen in the Dashboard Component

### Option A: Using Livewire's Echo Integration

Add to your Livewire component class:

```php
use Livewire\Attributes\On;

class DashboardManager extends Component
{
    public int $updateCount = 0;

    #[On('echo:dashboard,DashboardUpdated')]
    public function handleDashboardUpdate($event): void
    {
        $this->updateCount++;

        // Refresh specific data
        $this->loadDashboards();

        // Or dispatch a browser event
        $this->dispatch('dashboard-refreshed', [
            'message' => $event['message'],
            'data' => $event['data'],
        ]);
    }
}
```

### Option B: Using JavaScript in the Blade View

In your dashboard Blade view:

```blade
<div>
    {{-- Your dashboard content --}}

    @script
    <script>
        // Listen for dashboard updates
        if (window.Echo) {
            window.Echo.channel('dashboard')
                .listen('DashboardUpdated', (e) => {
                    console.log('Dashboard update received:', e);

                    // Refresh the Livewire component
                    $wire.$refresh();

                    // Or show a toast notification
                    window.dispatchEvent(new CustomEvent('notify', {
                        detail: {
                            type: 'success',
                            message: e.message
                        }
                    }));
                });
        }
    </script>
    @endscript
</div>
```

### Option C: Using Alpine.js for Interactive Updates

```blade
<div x-data="dashboardRealtime">
    <div class="badge badge-primary" x-show="hasUpdate">
        <span x-text="updateMessage"></span>
    </div>

    {{-- Dashboard content --}}

    @script
    <script>
        Alpine.data('dashboardRealtime', () => ({
            hasUpdate: false,
            updateMessage: '',

            init() {
                if (window.Echo) {
                    window.Echo.channel('dashboard')
                        .listen('DashboardUpdated', (e) => {
                            this.hasUpdate = true;
                            this.updateMessage = e.message;

                            // Auto-hide after 5 seconds
                            setTimeout(() => {
                                this.hasUpdate = false;
                            }, 5000);

                            // Refresh Livewire component
                            this.$wire.$refresh();
                        });
                }
            }
        }));
    </script>
    @endscript
</div>
```

## Step 4: Add Visual Feedback

Create a notification component for real-time updates:

```blade
<div
    x-data="{ show: false, message: '' }"
    x-on:notify.window="show = true; message = $event.detail.message; setTimeout(() => show = false, 3000)"
    x-show="show"
    x-transition
    class="fixed top-4 right-4 bg-success text-success-content px-6 py-4 rounded-lg shadow-lg z-50"
>
    <p x-text="message"></p>
</div>
```

## Complete Example: Real-time Contact Counter

Here's a complete example showing a live contact counter:

```blade
<div x-data="liveContactCounter" class="card bg-base-200">
    <div class="card-body">
        <h2 class="card-title">Live Contact Count</h2>
        <div class="stat">
            <div class="stat-value" x-text="contactCount"></div>
            <div class="stat-desc">Total Contacts</div>
        </div>

        <div x-show="recentContact" class="alert alert-success mt-4">
            <span x-text="'Latest: ' + recentContact"></span>
        </div>
    </div>

    @script
    <script>
        Alpine.data('liveContactCounter', () => ({
            contactCount: {{ $initialContactCount }},
            recentContact: '',

            init() {
                if (window.Echo) {
                    window.Echo.channel('dashboard')
                        .listen('DashboardUpdated', (e) => {
                            if (e.data.contact_id) {
                                this.contactCount++;
                                this.recentContact = e.data.callsign +
                                    ' on ' + e.data.band +
                                    ' (' + e.data.mode + ')';

                                // Clear after 5 seconds
                                setTimeout(() => {
                                    this.recentContact = '';
                                }, 5000);
                            }
                        });
                }
            }
        }));
    </script>
    @endscript
</div>
```

## Testing the Setup

1. Start all services:
   ```bash
   composer run dev
   ```

2. Open your dashboard in a browser

3. In another terminal, trigger a test broadcast:
   ```bash
   php artisan tinker
   ```

   ```php
   use App\Events\Dashboard\DashboardUpdated;

   DashboardUpdated::dispatch('Test message', [
       'contact_id' => 123,
       'callsign' => 'W1AW',
       'band' => '20m',
       'mode' => 'SSB',
   ]);
   ```

4. You should see the update appear in your dashboard immediately!

## Debugging Checklist

If broadcasts aren't working:

1. ✅ Is Reverb running? (`php artisan reverb:start`)
2. ✅ Is the queue worker running? (`php artisan queue:listen`)
3. ✅ Check browser console for Echo connection
4. ✅ Check Reverb logs for incoming broadcasts
5. ✅ Verify `.env` has correct `REVERB_*` settings
6. ✅ Run `npm run build` after changing `.env`

## Common Patterns

### Broadcast to Specific Event

```php
public function broadcastOn(): array
{
    return [
        new Channel('dashboard.event.' . $this->eventId),
    ];
}
```

```javascript
Echo.channel(`dashboard.event.${eventId}`)
    .listen('DashboardUpdated', (e) => {
        // Handle event-specific update
    });
```

### Broadcast to Authenticated Users Only

```php
public function broadcastOn(): array
{
    return [
        new PrivateChannel('dashboard.user.' . $this->userId),
    ];
}
```

In `routes/channels.php`:

```php
Broadcast::channel('dashboard.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

```javascript
Echo.private(`dashboard.user.${userId}`)
    .listen('DashboardUpdated', (e) => {
        // Handle private update
    });
```

### Track Who's Viewing the Dashboard

```php
public function broadcastOn(): array
{
    return [
        new PresenceChannel('dashboard.viewers'),
    ];
}
```

```javascript
Echo.join('dashboard.viewers')
    .here((users) => {
        console.log('Current viewers:', users);
    })
    .joining((user) => {
        console.log(user.name + ' joined');
    })
    .leaving((user) => {
        console.log(user.name + ' left');
    });
```

In `routes/channels.php`:

```php
Broadcast::channel('dashboard.viewers', function ($user) {
    if ($user) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'callsign' => $user->callsign,
        ];
    }
});
```
