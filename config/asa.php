<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ASA Webhook
    |--------------------------------------------------------------------------
    |
    | host    : domain ASA beserta skemanya, mis. https://asa.globalxtreme-solutions.net
    | token   : ASA Token milik service ini (menu Services di ASA). Tanpa token ini webhook ditolak
    | timeout : batas waktu panggilan dalam detik
    |
    */

    'webhook' => [
        'host' => env('ASA_WEBHOOK_HOST'),
        'token' => env('ASA_WEBHOOK_TOKEN'),
        'timeout' => env('ASA_WEBHOOK_TIMEOUT', 10),
    ],

];
