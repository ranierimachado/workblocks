<?php

return [
    'password' => env('WORKBLOCKS_PASSWORD'),
    'backup_directory' => env('BACKUP_DIRECTORY', storage_path('app/backups')),
    'feed_enabled' => env('WORKBLOCKS_FEED_ENABLED', false),
    'feed_directory' => env('WORKBLOCKS_FEED_DIRECTORY', base_path('../documents')),
];
