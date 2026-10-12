<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticket number
    |--------------------------------------------------------------------------
    | Format: {prefix}-{year}-{zero padded sequence}, e.g. TKT-2026-000001.
    | Padding is a MINIMUM width: after 999,999 tickets the number simply grows
    | to 7 digits (TKT-2026-1000000) - nothing breaks, nothing needs migrating.
    */
    'prefix' => env('TICKET_PREFIX', 'TKT'),
    'number_padding' => 6,

    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    | Business hours (e.g. Sun-Thu 09:00-18:00) are interpreted in this zone.
    | Database values are always stored in UTC (app.timezone).
    */
    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | Assignment
    |--------------------------------------------------------------------------
    */
    'auto_assign' => (bool) env('TICKET_AUTO_ASSIGN', true),

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    | Resolved tickets are closed automatically after this many days (0 = never).
    */
    'auto_close_days' => (int) env('TICKET_AUTO_CLOSE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Attachments (private disk, never web accessible)
    |--------------------------------------------------------------------------
    */
    'attachments' => [
        'disk' => env('TICKET_ATTACHMENT_DISK', 'local'),
        'max_kb' => 5120,           // 5 MB per file
        'max_files' => 5,           // per request
        'mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'txt', 'csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV import
    |--------------------------------------------------------------------------
    */
    'import' => [
        'chunk_size' => 500,
        'max_kb' => 51200,          // 50 MB upload
        'disk' => 'local',
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA scheduler
    |--------------------------------------------------------------------------
    */
    'sla' => [
        'scan_chunk' => 200,
    ],
];
