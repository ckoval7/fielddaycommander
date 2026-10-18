{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Live-update (Reverb) settings for resources/js/bootstrap.js. Host, port and scheme stay null unless
     REVERB_CLIENT_* is set, so browsers connect to the address the page was loaded from. --}}
<meta name="reverb-config" content="{{ json_encode(['key' => config('reverb.apps.apps.0.key'), 'host' => config('reverb.client.host'), 'port' => config('reverb.client.port'), 'scheme' => config('reverb.client.scheme')]) }}">
