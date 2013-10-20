<?php

/**
 * Redis Cache Store - Settings
 *
 * @package     cachestore_redis
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$settings->add(
  new admin_setting_configtext(
    'cachestore_redis/test_server',
    get_string('test_server', 'cachestore_redis'),
    get_string('test_server_desc', 'cachestore_redis'),
    '',
    PARAM_TEXT,
    16
  )
);
