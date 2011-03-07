<?php
/**
 * Main script - load and start daemons
 *
 * @example Usage - /path/to/php daemonloader.php (start|stop) daemon-name
 * @version $Id$
 */
error_reporting ( E_ALL );
ini_set ( 'display_errors', 1 );

if ( ! isset ( $_SERVER [ 'HTTP_HOST' ] ) )
    $_SERVER [ 'HTTP_HOST' ] = '127.0.0.1';