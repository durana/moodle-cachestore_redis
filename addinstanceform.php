<?php

/**
 * Redis Cache Store - Add instance form
 *
 * @package     cachestore_redis
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/cache/forms.php');
require_once($CFG->dirroot.'/cache/stores/memcached/lib.php');

/**
 * Form for adding instance of Redis Cache Store.
 *
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_redis_addinstance_form extends cachestore_addinstance_form {
    /** 
     * Builds the form for creating an instance.
     */
    protected function configuration_definition() {
        $form = $this->_form;

        $form->addElement('text', 'server', get_string('server', 'cachestore_redis'), array('size' => 24));
        $form->addHelpButton('server', 'server', 'cachestore_redis');
        $form->addRule('server', get_string('required'), 'required');
        $form->setType('server', PARAM_TEXT);
    }
}