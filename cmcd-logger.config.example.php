<?php
// Copy to cmcd-logger.config.php and adjust. Any option omitted keeps its default.
return [
    'data_dir'          => '/var/lib/cmcd-logger',   // keep outside the web root
    'retention'         => 'P6M',                    // ISO 8601 duration or number of days
    'cors_allow_origin' => '*',
    'store_client_info' => true,
    'viewer_user'       => '',                       // set both to enable Basic auth on the viewer
    'viewer_pass'       => '',
    'display_timezone'  => 'UTC',
];
