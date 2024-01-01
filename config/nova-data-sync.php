<?php

return [

    'imports' => [
        'disk' => env('MEDIA_DISK', 'public'),
        'chunk_size' => 1000,
        'queue' => 'default',
    ],

    'exports' => [
        'disk' => env('MEDIA_DISK', 'public'),
        'chunk_size' => 1000,
        'queue' => 'default',
    ],

    'nova_resources' => [

        /**
         * Since users are defined as morphable, we need to specify the Nova resource
         * associated with the users we want.
         */
        'users' => [
            \App\Nova\User::class,
        ],

    ],
];
