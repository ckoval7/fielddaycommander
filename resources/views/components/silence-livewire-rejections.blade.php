{{-- Suppress unhandled-promise-rejection noise from Livewire 4.

    Livewire rejects every pending action promise on a request error with a
    fixed `{ status, body, json, errors }` shape (livewire.esm.js:
    rejectActionPromises). Polled actions and dispatched events have no
    `.catch()` attached, so those rejections surface as
    "Unhandled Promise Rejection" noise even though our interceptRequest
    handler in app.js has already dealt with the response.

    This must run before laravel/boost's BrowserLogger script (injected by
    the InjectBoost middleware just before </head>) so stopImmediatePropagation
    keeps the rejection out of the MCP browser-logs feed too. Keep this
    inline and earlier than @vite. --}}
<script>
    (function () {
        window.addEventListener('unhandledrejection', function (event) {
            var reason = event.reason;
            if (
                reason && typeof reason === 'object'
                && 'status' in reason
                && 'body' in reason
                && 'json' in reason
                && 'errors' in reason
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    })();
</script>
