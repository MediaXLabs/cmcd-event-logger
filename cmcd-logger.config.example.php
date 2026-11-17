<?php
// Copy to cmcd-logger.config.php and adjust. Any option omitted keeps its default.
return [
    'data_dir'          => '/var/lib/cmcd-logger',   // local disk, writable, outside the web root
    'db_file'           => 'cmcd-events.sqlite',
    'retention'         => 'P6M',                    // ISO 8601 duration or number of days
    'cors_allow_origin' => '*',
    'store_client_info' => true,
    'trusted_proxies'   => [],                       // e.g. ['127.0.0.1', '::1', '10.0.0.0/8'] when behind a proxy/LB
    'viewer_user'       => '',                       // set both to enable Basic auth on the viewer
    'viewer_pass'       => '',
    'display_timezone'  => 'UTC',
];
