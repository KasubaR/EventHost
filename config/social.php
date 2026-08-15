<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Profiles
    |--------------------------------------------------------------------------
    |
    | Public profile URLs rendered by <x-social-links />, used in the site footer
    | and on the contact page. Leave an entry null (or unset the env var) and that
    | icon is not rendered at all — better an absent icon than one linking to "#".
    | Set the values in .env once the accounts exist.
    |
    */

    'x' => env('SOCIAL_X_URL'),
    'linkedin' => env('SOCIAL_LINKEDIN_URL'),
    'facebook' => env('SOCIAL_FACEBOOK_URL'),
    'instagram' => env('SOCIAL_INSTAGRAM_URL'),

];
