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

        $form->addElement('text', 'prefix', get_string('prefix', 'cachestore_redis'), array('size' => 16));
        $form->setType('prefix', PARAM_TEXT); // We set to text but we have a rule to limit to alphanumext.
        $form->addHelpButton('prefix', 'prefix', 'cachestore_redis');
        $form->addRule('prefix', get_string('prefixinvalid', 'cachestore_redis'), 'regex', '#^[a-zA-Z0-9\-_]+$#');
    }
}