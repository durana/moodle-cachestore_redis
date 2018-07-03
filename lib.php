<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Redis Cache Store - Main library
 *
 * @package   cachestore_redis
 * @copyright 2013 Adam Durana
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Redis Cache Store
 *
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @todo TTL support was removed, but might be able to add it back by setting
 *       the TTL on the hash key.  So, after a set, we could use http://redis.io/commands/pttl
 *       to see if the hash is set to expire, if not, set a TTL on it.  Must prevent it from
 *       doing it on every set though.
 */
class cachestore_redis extends cache_store implements cache_is_key_aware, cache_is_lockable, cache_is_configurable {
    /**
     * Name of this store.
     *
     * @var string
     */
    protected $name;

    /**
     * The definition hash, used for hash key
     *
     * @var string
     */
    protected $hash;

    /**
     * Flag for readiness!
     *
     * @var boolean
     */
    protected $isready = false;

    /**
     * Cache definition for this store.
     *
     * @var cache_definition
     */
    protected $definition = null;

    /**
     * Connection to Redis for this store.
     *
     * @var Redis
     */
    protected $redis;

    /**
     * Determines if the requirements for this type of store are met.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        return class_exists('Redis');
    }

    /**
     * Determines if this type of store supports a given mode.
     *
     * @param int $mode
     * @return bool
     */
    public static function is_supported_mode($mode) {
        return ($mode === self::MODE_APPLICATION);
    }

    /**
     * Get the features of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_DATA_GUARANTEE;
    }

    /**
     * Get the supported modes of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION;
    }

    /**
     * Constructs an instance of this type of store.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        $this->name = $name;

        // During unit test purge, it goes off process and no config is passed.
        if (PHPUNIT_TEST && empty($configuration)) {
            // The name is important because it is part of the prefix.
            $this->name    = self::get_testing_name();
            $configuration = self::get_testing_configuration();
        } else if (!array_key_exists('server', $configuration) || empty($configuration['server'])) {
            return;
        }
        $prefix = !empty($configuration['prefix']) ? $configuration['prefix'] : '';
        $password = !empty($configuration['password']) ? $configuration['password'] : '';
        $this->redis = $this->new_redis($configuration['server'], $prefix, $password);
    }

    /**
     * Create a new Redis instance and
     * connect to the server.
     *
     * @param string $server The server connection string
     * @param string $prefix The key prefix
     * @param string $password The server connection password
     * @return Redis
     */
    protected function new_redis($server, $prefix = '', $password = '') {
        $redis = new Redis();
        if ($redis->connect($server)) {
            if (!empty($password)) {
                $redis->auth($password);
            }
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            $redis->setOption(Redis::OPT_PREFIX, $prefix.$this->name.'-');

            $this->isready = $this->ping($redis);
        } else {
            $this->isready = false;
        }
        return $redis;
    }

    /**
     * See if we can ping Redis server
     *
     * @param Redis $redis
     * @return bool
     */
    protected function ping(Redis $redis) {
        try {
            if ($redis->ping() === false) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Get the name of the store.
     *
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Initialize the store.
     *
     * @param cache_definition $definition
     * @return bool
     */
    public function initialise(cache_definition $definition) {
        $this->definition = $definition;
        $this->hash       = $definition->generate_definition_hash();
        return true;
    }

    /**
     * Determine if the store is initialized.
     *
     * @return bool
     */
    public function is_initialised() {
        return ($this->definition !== null);
    }

    /**
     * Determine if the store is ready for use.
     *
     * @return bool
     */
    public function is_ready() {
        return $this->isready;
    }

    /**
     * Get the value associated with a given key.
     *
     * @param string $key The key to get the value of.
     * @return mixed The value of the key, or false if there is no value associated with the key.
     */
    public function get($key) {
        return $this->redis->hGet($this->hash, $key);
    }

    /**
     * Get the values associated with a list of keys.
     *
     * @param array $keys The keys to get the values of.
     * @return array An array of the values of the given keys.
     */
    public function get_many($keys) {
        return $this->redis->hMGet($this->hash, $keys);
    }

    /**
     * Set the value of a key.
     *
     * @param string $key The key to set the value of.
     * @param mixed $value The value.
     * @return bool True if the operation succeeded, false otherwise.
     */
    public function set($key, $value) {
        return ($this->redis->hSet($this->hash, $key, $value) !== false);
    }

    /**
     * Set the values of many keys.
     *
     * @param array $keyvaluearray An array of key/value pairs. Each item in the array is an associative array
     *      with two keys, 'key' and 'value'.
     * @return int The number of key/value pairs successfuly set.
     */
    public function set_many(array $keyvaluearray) {
        $pairs = [];
        foreach ($keyvaluearray as $pair) {
            $pairs[$pair['key']] = $pair['value'];
        }
        if ($this->redis->hMSet($this->hash, $pairs)) {
            return count($pairs);
        }
        return 0;
    }

    /**
     * Delete the given key.
     *
     * @param string $key The key to delete.
     * @return bool True if the delete operation succeeds, false otherwise.
     */
    public function delete($key) {
        return ($this->redis->hDel($this->hash, $key) > 0);
    }

    /**
     * Delete many keys.
     *
     * @param array $keys The keys to delete.
     * @return int The number of keys successfully deleted.
     */
    public function delete_many(array $keys) {
        array_unshift($keys, $this->hash);
        return call_user_func_array(array($this->redis, 'hDel'), $keys);
    }

    /**
     * Purges all keys from the store.
     *
     * @return bool
     */
    public function purge() {
        return ($this->redis->del($this->hash) !== false);
    }

    /**
     * Cleans up after an instance of the store.
     */
    public function instance_deleted() {
        $this->purge();
        $this->redis->close();
        unset($this->redis);
    }

    /**
     * Creates an instance of the store for testing.
     *
     * @param cache_definition $definition
     * @return mixed An instance of the store, or false if an instance cannot be created.
     */
    public static function initialise_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        $config = get_config('cachestore_redis');
        if (empty($config->test_server)) {
            return false;
        }
        $cache = new cachestore_redis('Redis test', ['server' => $config->test_server]);
        $cache->initialise($definition);

        return $cache;
    }

    /**
     * Determines if the store has a given key.
     *
     * @see cache_is_key_aware
     * @param string $key The key to check for.
     * @return bool True if the key exists, false if it does not.
     */
    public function has($key) {
        return $this->redis->hExists($this->hash, $key);
    }

    /**
     * Determines if the store has any of the keys in a list.
     *
     * @see cache_is_key_aware
     * @param array $keys The keys to check for.
     * @return bool True if any of the keys are found, false none of the keys are found.
     */
    public function has_any(array $keys) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines if the store has all of the keys in a list.
     *
     * @see cache_is_key_aware
     * @param array $keys The keys to check for.
     * @return bool True if all of the keys are found, false otherwise.
     */
    public function has_all(array $keys) {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tries to acquire a lock with a given name.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to acquire.
     * @param string $ownerid Information to identify owner of lock if acquired.
     * @return bool True if the lock was acquired, false if it was not.
     */
    public function acquire_lock($key, $ownerid) {
        return $this->redis->setnx($key, $ownerid);
    }

    /**
     * Checks a lock with a given name and owner information.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to check.
     * @param string $ownerid Owner information to check existing lock against.
     * @return mixed True if the lock exists and the owner information matches, null if the lock does not
     *      exist, and false otherwise.
     */
    public function check_lock_state($key, $ownerid) {
        $result = $this->redis->get($key);
        if ($result === $ownerid) {
            return true;
        }
        if ($result === false) {
            return null;
        }
        return false;
    }

    /**
     * Releases a given lock if the owner information matches.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to release.
     * @param string $ownerid Owner information to use.
     * @return bool True if the lock is released, false if it is not.
     */
    public function release_lock($key, $ownerid) {
        if ($this->check_lock_state($key, $ownerid)) {
            return ($this->redis->del($key) !== false);
        }
        return false;
    }

    /**
     * Creates a configuration array from given 'add instance' form data.
     *
     * @see cache_is_configurable
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        $config = array('server' => $data->server, 'prefix' => $data->prefix);

        if (property_exists($data, 'password')) {
            $config['password'] = $data->password;
        }
        return $config;
    }

    /**
     * Sets form data from a configuration array.
     *
     * @see cache_is_configurable
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config) {
        $data = array();
        $data['server'] = $config['server'];
        $data['prefix'] = !empty($config['prefix']) ? $config['prefix'] : '';
        $data['password'] = !empty($config['password']) ? $config['password'] : '';
        $editform->set_data($data);
    }

    public static function initialise_unit_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        if (!self::ready_to_be_used_for_testing()) {
            return false;
        }

        $store = new cachestore_redis(self::get_testing_name(), self::get_testing_configuration());
        if (!$store->is_ready()) {
            return false;
        }
        $store->initialise($definition);

        return $store;
    }

    public static function ready_to_be_used_for_testing() {
        return defined('CACHESTORE_REDIS_TEST_SERVER');
    }

    /**
     * Return configuration to use when unit testing.
     *
     * @return array
     * @throws coding_exception
     */
    public static function get_testing_configuration() {
        global $DB;

        if (!self::are_requirements_met()) {
            throw new coding_exception('Redis cache store not setup for testing');
        }
        $config = [
            'server' => CACHESTORE_REDIS_TEST_SERVER,
            'prefix' => $DB->get_prefix(),
        ];

        if (defined('CACHESTORE_REDIS_TEST_PASSWORD')) {
            $config['password'] = CACHESTORE_REDIS_TEST_PASSWORD;
        }

        return $config;
    }

    /**
     * Get the name to use when unit testing.
     *
     * @return string
     */
    private static function get_testing_name() {
        return 'test_application';
    }
}
