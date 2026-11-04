<?php

declare(strict_types=1);

return [
    'disks' => [
        'media-library' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            // Laravel 13 refuses two served disks on the same URL path, and the
            // framework's own "local" disk already serves "/storage". Keep "serve"
            // on: MediaUrls relies on it to sign temporary URLs for readers with
            // no session when media lives on a local disk.
            'url' => env('APP_URL') . '/media',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],
    ],
];
