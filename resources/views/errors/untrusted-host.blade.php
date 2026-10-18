{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Shown when a request's Host isn't trusted. Must not use url(), route() or asset(): they read the rejected Host and throw again. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Address not trusted</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; }
        main { max-width: 36rem; margin: 10vh auto; padding: 0 1rem; line-height: 1.5; }
        h1 { font-size: 1.5rem; }
        code { background: #e2e8f0; padding: 0.1rem 0.3rem; border-radius: 0.25rem; word-break: break-all; }
        a { color: #2563eb; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            code { background: #1e293b; }
            a { color: #93c5fd; }
        }
    </style>
</head>
<body>
    <main>
        <h1>This address isn't trusted</h1>
        <p>
            The server isn't set up to answer at
            @if ($host !== '')
                <code>{{ $host }}</code>.
            @else
                this address.
            @endif
            Ask your administrator to add it to <code>TRUSTED_HOSTS</code> in the server's settings.
        </p>
        @if ($appUrl !== '')
            <p>Until then, you can open FD Commander at <a href="{{ $appUrl }}">{{ $appUrl }}</a>.</p>
        @endif
    </main>
</body>
</html>
