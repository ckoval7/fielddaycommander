<?php

namespace App\Livewire\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class NotificationBell extends Component
{
    public int $unreadCount = 0;

    public Collection $notifications;

    public function mount(): void
    {
        $this->notifications = collect();
        $this->loadNotifications();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $userId = auth()->id();

        return $userId ? [
            "echo-private:user.{$userId},NewNotification" => 'loadNotifications',
        ] : [];
    }

    public function loadNotifications(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->notifications = $user->notifications()->latest()->limit(20)->get();
        $this->unreadCount = $user->unreadNotifications()->count();
    }

    public function markAsRead(string $id): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
            $this->loadNotifications();
        }
    }

    public function markAllAsRead(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->unreadNotifications->markAsRead();
        $this->loadNotifications();
    }

    public function openNotification(string $id): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
            $this->loadNotifications();

            $url = $notification->data['url'] ?? null;
            if ($url) {
                $this->redirect($url);
            }
        }
    }

    public function render(): View
    {
        return view('livewire.components.notification-bell');
    }
}
