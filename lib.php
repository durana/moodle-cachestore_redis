<?php

/**
 * Redis Cache Store - Main library
 *
 * @package     cachestore_redis
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Redis Cache Store
 *
 * @copyright   2013 Adam Durana
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_redis extends cache_store implements cache_is_key_aware, cache_is_lockable, cache_is_configurable, cache_is_searchable {
    /**
     * Name of this store.
     *
     * @var string
     */
    protected $name;

    /**
     * Server connection string.
     *
     * @var string
     */
    protected $server;

    /**
     * Used as part of the key prefix.
     *
     * @var string
     */
    protected $prefix;

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
        return ($mode === self::MODE_APPLICATION || $mode === self::MODE_SESSION);
    }

    /**
     * Get the features of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_DATA_GUARANTEE + self::SUPPORTS_NATIVE_TTL;
    }

    /**
     * Get the supported modes of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION + self::MODE_SESSION;
    }

    /**
     * Constructs an instance of this type of store.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        $this->name = $name;

        if (!array_key_exists('server', $configuration) || empty($configuration['server'])) {
            return;
        }
        $this->prefix  = !empty($configuration['prefix']) ? $configuration['prefix'] : '';
        $this->server  = $configuration['server'];
        $this->redis   = $this->new_redis();
        $this->isready = $this->ping();
    }

    /**
     * Stores are cloned all the time.  Create a new
     * Redis instance so it can have its own options.
     *
     * This is important for the prefix set by the definition
     * otherwise, stores start using the last set prefix.
     */
    public function __clone() {
        if ($this->redis instanceof Redis) {
            $this->redis = $this->new_redis();
        }
    }

    /**
     * Create a new Redis instance and
     * connect to the server.
     *
     * @return Redis
     */
    protected function new_redis() {
        $redis = new Redis();
        $redis->connect($this->server);
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        return $redis;
    }

    /**
     * See if we can ping Redis server
     *
     * @return bool
     */
    protected function ping() {
        try {
            $this->redis->ping();
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Redis returns keys with their prefix, but
     * in order to to use these keys for deletion,
     * lookup, etc, we need to remove that prefix.
     *
     * @param array $keys
     */
    protected function remove_prefix(&$keys) {
        if (empty($keys)) {
            return;
        }
        $length = strlen($this->redis->getOption(Redis::OPT_PREFIX));
        foreach ($keys as $index => $key) {
            $keys[$index] = substr($key, $length);
        }
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
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix.$this->name.$definition->generate_definition_hash().'-');
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
        return $this->redis->get($key);
    }

    /**
     * Get the values associated with a list of keys.
     *
     * @param array $keys The keys to get the values of.
     * @return array An array of the values of the given keys.
     */
    public function get_many($keys) {
        $values = $this->redis->mget($keys);
        return array_combine($keys, $values);
    }

    /**
     * Set the value of a key.
     *
     * @param string $key The key to set the value of.
     * @param mixed $value The value.
     * @return bool True if the operation succeeded, false otherwise.
     */
    public function set($key, $value) {
        $ttl = $this->definition->get_ttl();
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, $value);
        }
        return $this->redis->set($key, $value);
    }

    /**
     * Set the values of many keys.
     *
     * @param array $keyvalues An array of key/value pairs. Each item in the array is an associative array
     *      with two keys, 'key' and 'value'.
     * @return int The number of key/value pairs successfuly set.
     */
    public function set_many(array $keyvalues) {
        $ttl = $this->definition->get_ttl();
        if ($ttl > 0) {
            $pipeline = $this->redis->pipeline();
            foreach ($keyvalues as $pair) {
                $pipeline->setex($pair['key'], $ttl, $pair['value']);
            }
            $results = $pipeline->exec();
            $count = 0;
            foreach ($results as $result) {
                if ($result) {
                    $count++;
                }
            }
            return $count;
        }
        if ($this->redis->mset($keyvalues)) {
            return count($keyvalues);
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
        return $this->redis->delete($key);
    }

    /**
     * Delete many keys.
     *
     * @param array $keys The keys to delete.
     * @return int The number of keys successfully deleted.
     */
    public function delete_many(array $keys) {
        return $this->redis->delete($keys);
    }

    /**
     * Purges all keys from the store.
     *
     * @return bool
     */
    public function purge() {
        $keys = $this->find_all();
        if (!empty($keys)) {
            return $this->delete_many($keys) == count($keys);
        }
        return true;
    }

    /**
     * Finds all of the keys being used by the cache store.
     *
     * @return array.
     */
    public function find_all() {
        $keys = $this->redis->keys('*');
        $this->remove_prefix($keys);
        return $keys;
    }

    /**
     * Finds all of the keys whose keys start with the given prefix.
     *
     * @param string $prefix
     * @return array
     */
    public function find_by_prefix($prefix) {
        $keys = $this->redis->keys($prefix.'*');
        $this->remove_prefix($keys);
        return $keys;
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
        $cache_config = array();
        $cache_config['server'] = $config->test_server;
        $cache = new cachestore_redis('Redis test', $cache_config);
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
        return $this->redis->exists($key);
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
            return $this->redis->delete($key);
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
        return array('server' => $data->server, 'prefix' => $data->prefix);
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
        $editform->set_data($data);
    }
}
