<?php
/**
 * Copy this file one directory ABOVE public_html as cardinal-private.php.
 * It is outside the public web root and must never be added to the ZIP or git.
 *
 * Example location for a cPanel account:
 *   /home/CPANEL_USER/cardinal-private.php
 */
return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'YOUR_EXISTING_CARDINAL_DATABASE',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
];
