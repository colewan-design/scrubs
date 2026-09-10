<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups — §12
    |--------------------------------------------------------------------------
    |
    | A nightly dump is the minimum the brief asks for. Everything here is env
    | driven because it is infrastructure, not a business setting: the client
    | should never be able to turn backups off from the admin panel.
    |
    */

    // Where dumps are written before being copied off the server.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    | How many days of dumps to keep locally. Local retention is deliberately
    | short — the disk is the same one the database is on, so it protects
    | against a bad migration, not against losing the server. That is what the
    | off-server copy below is for.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    | Optional filesystem disk to copy each dump to. THIS IS THE ONE THAT
    | MATTERS: a backup living only on the machine it is backing up is not a
    | backup. Point it at an S3-compatible bucket in a Canadian region.
    */
    'disk' => env('BACKUP_DISK'),
    'disk_path' => env('BACKUP_DISK_PATH', 'database-backups'),

    /*
    | Verification. An untested backup is not a backup, so the command can
    | restore each dump into a scratch database and compare table counts before
    | it is trusted. The scratch database is created and dropped by the command,
    | so the connection user needs CREATE/DROP DATABASE.
    |
    | Left off by default because it doubles the runtime and needs that
    | privilege; the scheduled weekly run below turns it on.
    */
    'verify_database' => env('BACKUP_VERIFY_DATABASE', 'bulkscrubs_backup_check'),

    // Binaries. Set these when they are not on the web user's PATH.
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),

];
