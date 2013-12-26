<?php

/**
 * Redis Cache Store - English language strings
 *
 * @package     cachestore_redis
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Redis';

$string['server'] = 'Server';
$string['server_help'] = 'This sets the hostname or IP address of the Redis server to use.';

$string['test_server'] = 'Test Server';
$string['test_server_desc'] = 'Redis server to use for testing.';

$string['prefix'] = 'Key prefix';
$string['prefix_help'] = 'This prefix is used for all key names on the Redis server.
* If you only have one Moodle instance using this server, you can leave this value default.
* Due to key length restrictions, a maximum of 5 characters is permitted.';
$string['prefixinvalid'] = 'Invalid prefix. You can only use a-z A-Z 0-9-_.';
