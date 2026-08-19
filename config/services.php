<?php

declare(strict_types=1);

return [
    'football' => [
        // mock | apifootball | football-data
        'provider' => env('FOOTBALL_PROVIDER', 'mock'),
        'api_key' => env('FOOTBALL_API_KEY', ''),
        'base_url' => env('FOOTBALL_API_BASE', 'https://v3.football.api-sports.io'),
        'team_id' => (int) env('FOOTBALL_TEAM_ID', 502),
        'season' => (int) env('FOOTBALL_SEASON', (int) date('Y')),
        'cache_ttl_minutes' => (int) env('FOOTBALL_CACHE_TTL', 360),
        'team_name' => 'Fiorentina',
        'timeout' => 10,
    ],

    'social' => [
        // mock | live
        'provider' => env('SOCIAL_PROVIDER', 'mock'),
        'timeout' => 10,

        'instagram' => [
            'token' => env('INSTAGRAM_TOKEN', ''),
            'user_id' => env('INSTAGRAM_USER_ID', ''),
            'limit' => 8,
        ],
        'facebook' => [
            'token' => env('FACEBOOK_TOKEN', ''),
            'page_id' => env('FACEBOOK_PAGE_ID', ''),
            'limit' => 6,
        ],
        'youtube' => [
            'api_key' => env('YOUTUBE_API_KEY', ''),
            'channel_id' => env('YOUTUBE_CHANNEL_ID', ''),
            'limit' => 4,
        ],

        'meta' => [
            'app_id' => env('META_APP_ID', ''),
            'app_secret' => env('META_APP_SECRET', ''),
        ],
    ],
];
