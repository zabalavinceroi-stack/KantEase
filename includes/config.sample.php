<?php

declare(strict_types=1);

/**
 * KantEase — sample configuration.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE IS A TEMPLATE AND IS SAFE TO COMMIT. IT CONTAINS NO SECRETS.
 *
 * To make KantEase run on your machine:
 *
 *   1. Copy this file to  includes/config.local.php
 *   2. Edit the db.* values below to match your XAMPP MySQL/MariaDB account
 *   3. config.local.php is listed in .gitignore and can never be committed
 *      by accident. includes/.htaccess also blocks it over HTTP.
 * ---------------------------------------------------------------------------
 *
 * @return array<string, mixed>
 */

return [
    /*
     |--------------------------------------------------------------------------
     | Database
     |--------------------------------------------------------------------------
     | The default below matches a stock XAMPP install: user "root" with an
     | empty password on localhost. If you set a root password in the XAMPP
     | installer, put it in 'password' here.
     |
     | 'name' must match the database created by database/database.sql.
     | A fresh install defaults to 'kantease_db'.
     | A migrated legacy database keeps its original name, usually 'canteen_db'.
     */
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'kantease_db',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    /*
     |--------------------------------------------------------------------------
     | Application
     |--------------------------------------------------------------------------
     */
    'app' => [
        'name' => 'KantEase',

        /*
         * Used for every date and time the application generates or displays.
         * Must be a valid PHP timezone identifier.
         */
        'timezone' => 'Asia/Manila',

        /*
         * How long an idle session survives, in seconds.
         * 28800 = 8 hours, matching the original Node.js SESSION_MS so no user
         * is logged out sooner than before.
         */
        'session_idle_timeout' => 28800,

        /*
         * Absolute session lifetime, in seconds. Reached even if the user is
         * actively clicking. 43200 = 12 hours.
         */
        'session_absolute_timeout' => 43200,

        /*
         * Where KantEase is served from, relative to the document root.
         *   XAMPP  -> http://localhost/KantEase/   =>  '/KantEase'
         *   LAN    -> http://192.168.1.50/KantEase/ => '/KantEase'
         *   vhost  -> http://canteen.school.local/  =>  ''
         *
         * Leave this alone unless you change the folder name or set up a
         * virtual host. Setup asks for it if it ever needs adjusting.
         */
        'base_path' => '/KantEase',

        /*
         * Set false only for the one-time installer, which runs before any
         * account exists. Leave it true everywhere else.
         */
        'start_session' => true,

        /*
         * false = errors are logged to the server, never shown to a student.
         * true  = errors are also printed on screen. Never enable on a machine
         *         students can reach.
         */
        'dev_mode' => false,
    ],

    /*
     |--------------------------------------------------------------------------
     | Security
     |--------------------------------------------------------------------------
     */
    'security' => [
        /*
         * Failed logins allowed for one account before it is locked, and how
         * long the lock lasts. Closes audit finding VULN-4: the original
         * /api/login had no throttle of any kind.
         */
        'login_max_attempts'     => 5,
        'login_lockout_seconds'  => 300,

        /*
         * Failed-attempt rows older than this are pruned. Keeps the
         * login_attempts table small without ever losing useful history.
         */
        'login_attempt_retention_hours' => 24,

        'password_min_length' => 8,
        'password_max_length' => 128,

        /*
         * Largest product photo accepted, in bytes. 2 MB is ample for a
         * canteen menu photo and keeps the uploads folder small on a school
         * machine with limited disk space.
         */
        'upload_max_bytes' => 2_097_152,

        /*
         * Allowed product-image extensions and the MIME types each must
         * actually report. Both the extension and the sniffed MIME type must
         * pass; a renamed .php will not match.
         */
        'upload_allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'upload_allowed_mimes'      => [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Order rules
     |--------------------------------------------------------------------------
     | These encode the canteen's business rules in one place so they are not
     | scattered across page files.
     */
    'orders' => [
        'max_distinct_items'   => 20,
        'max_quantity_per_item' => 100,

        /*
         * Inventory policy.
         *   'deduct_on_place' is what the original application did and is
         *   what KantEase keeps: stock leaves the shelf the moment an order
         *   is placed, and returns once if the order is cancelled.
         *   'reserve_on_place' holds stock in a separate reserved column
         *   instead. Changing this value later is NOT a supported migration.
         */
        'stock_policy' => 'deduct_on_place',

        /*
         * How long a Pending order stays claimable by the student before the
         * canteen may cancel it as abandoned. 0 disables abandonment.
         */
        'abandon_after_minutes' => 120,
    ],
];