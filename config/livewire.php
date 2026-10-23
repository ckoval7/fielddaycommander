<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

return [

    /*
    |---------------------------------------------------------------------------
    | Temporary File Uploads
    |---------------------------------------------------------------------------
    |
    | Livewire checks every upload against these rules before a component's
    | own validation runs, and defaults to 12 MB. Gallery photos are accepted
    | up to 25 MB, so this must allow that. Components that want a smaller
    | limit (ADIF import, logo, equipment photo) enforce it in their own rules.
    | PHP (upload_max_filesize, post_max_size) and the web server's request
    | body limit must also allow this size.
    |
    */

    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:25600'],
    ],

];
