<?php

return [
    'error' => 'Error',
    'back_home' => 'Back to home',

    '404' => [
        'title' => 'Page not found',
        'message' => 'This page took a wrong turn. It may have been moved, renamed, or never existed.',
    ],

    '403' => [
        'title' => 'Access denied',
        'message' => "You don't have permission to view this page.",
    ],

    // Leans on remember-me deliberately: since sign-ins are remembered, a 419 almost never
    // means the visitor was signed out -- and a page that says so would send them hunting
    // for a login problem that isn't there.
    '419' => [
        'title' => 'Page expired',
        'message' => 'This page sat open too long and its security token expired. Go back home and try again -- you are almost certainly still signed in.',
    ],

    '500' => [
        'title' => 'Something broke',
        'message' => 'An unexpected error occurred on our end. Try again in a moment.',
    ],

    '503' => [
        'title' => 'Be right back',
        'message' => 'UeType is down for maintenance. We\'ll be back shortly.',
    ],
];
