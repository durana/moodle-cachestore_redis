<?php

require_once(dirname(__DIR__).'/lib.php');

class cachestore_redis_test extends basic_testcase {
    /**
     * @var cachestore_redis
     */
    protected $store;

    protected function setUp() {
        if (!defined('CACHESTORE_REDIS_TEST_SERVER')) {
            $this->markTestSkipped('Must define CACHESTORE_REDIS_TEST_SERVER to test Redis cache store');
        }
        if (!cachestore_redis::are_requirements_met()) {
            $this->markTestSkipped('Requirements for Redis cache store are not met');
        }
        $this->store = new cachestore_redis('test', array(
            'server' => CACHESTORE_REDIS_TEST_SERVER,
            'prefix' => 'phpunit',
        ));
        $this->store->initialise(cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'foo_bar', 'baz'));
    }

    protected function tearDown() {
        if (defined('CACHESTORE_REDIS_TEST_SERVER')) {
            $this->store->instance_deleted();
        }
    }

    public function test_initialise() {
        $this->assertTrue($this->store->initialise(new cache_definition()));
        $this->assertTrue($this->store->is_initialised());
    }

    public function test_is_read() {
        $this->assertTrue($this->store->is_ready());
    }

    public function test_set_and_get() {
        $this->assertFalse($this->store->get('phpunit'));
        $this->assertTrue($this->store->set('phpunit', 'expected'));
        // The Redis client returns different value on second set.
        $this->assertTrue($this->store->set('phpunit', 'expected'));
        $this->assertEquals('expected', $this->store->get('phpunit'));
    }

    /**
     * @dataProvider values_provider
     * @param $value
     */
    public function test_get_values($value) {
        $this->assertTrue($this->store->set('phpunit', $value));
        $this->assertEquals($value, $this->store->get('phpunit'));
    }

    public function test_get_many() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->set('bat', 'baz'));
        $this->assertTrue($this->store->set('this', 'that'));

        $expected = array(
            'foo' => 'bar',
            'this' => 'that',
        );
        $this->assertEquals($expected, $this->store->get_many(array('foo', 'this')));
    }

    public function test_set_many() {
        $this->assertEquals(3, $this->store->set_many(array(
            'foo' => 'bar',
            'bat' => 'baz',
            'that' => 'this',
        )));

        $this->assertEquals('bar', $this->store->get('foo'));
        $this->assertEquals('baz', $this->store->get('bat'));
        $this->assertEquals('this', $this->store->get('that'));
    }

    /**
     * Make sure set many behaves like set in how
     * it handles values.
     *
     * @dataProvider values_provider
     * @param $value
     */
    public function test_set_many_values($value) {
        $this->assertEquals(1, $this->store->set_many(array(
            'test' => $value,
        )));

        $this->assertEquals($value, $this->store->get('test'));
    }

    public function test_delete() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->set('bat', 'baz'));

        $this->assertTrue($this->store->delete('bat'));

        $this->assertEquals('bar', $this->store->get('foo'));
        $this->assertFalse($this->store->get('bat'));
    }

    public function test_delete_many() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->set('bat', 'baz'));
        $this->assertTrue($this->store->set('this', 'that'));

        $keys = array('foo', 'bat');
        $this->assertEquals(2, $this->store->delete_many($keys));

        // The $keys is modified in delete_many, ensure it does not change out here.
        $this->assertEquals(array('foo', 'bat'), $keys);

        $this->assertEquals('that', $this->store->get('this'));
        $this->assertFalse($this->store->get('foo'));
        $this->assertFalse($this->store->get('bat'));
    }

    public function test_purge() {
        $otherstore = new cachestore_redis('test2', array(
            'server' => CACHESTORE_REDIS_TEST_SERVER,
            'prefix' => 'phpunit2',
        ));

        $this->assertTrue($otherstore->set('nopurge', 'value'));

        // Calling purge on empty cache should be OK.
        $this->assertTrue($this->store->purge());

        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->set('bat', 'baz'));
        $this->assertTrue($this->store->set('this', 'that'));

        $this->assertTrue($this->store->purge());

        $this->assertFalse($this->store->get('foo', 'bar'));
        $this->assertFalse($this->store->get('bat', 'baz'));
        $this->assertFalse($this->store->get('this', 'that'));

        // Our other store should not be affected!
        $this->assertEquals('value', $otherstore->get('nopurge'));

        // Cleanup...
        $this->assertTrue($otherstore->purge());
    }

    public function test_has() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->has('foo'));
        $this->assertFalse($this->store->has('bat'));
    }

    public function test_has_any() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->has_any(array('bat', 'foo')));
    }

    public function test_has_all() {
        $this->assertTrue($this->store->set('foo', 'bar'));
        $this->assertTrue($this->store->set('bat', 'baz'));
        $this->assertTrue($this->store->has_all(array('foo', 'bat')));
        $this->assertFalse($this->store->has_all(array('foo', 'bat', 'this')));
    }

    public function test_lock() {
        $this->assertTrue($this->store->acquire_lock('lock', '123'));
        $this->assertTrue($this->store->check_lock_state('lock', '123'));
        $this->assertFalse($this->store->check_lock_state('lock', '321'));
        $this->assertNull($this->store->check_lock_state('notalock', '123'));
        $this->assertFalse($this->store->release_lock('lock', '321'));
        $this->assertTrue($this->store->release_lock('lock', '123'));
    }

    public function values_provider() {
        return array(
            array(1),
            array(0),
            array(true),
            array(false),
            array(array('foo' => 'bar', 'bat' => 'baz')),
            array(array('foo' => 'bar', 'bat' => array('bazzy' => 'baz'))),
            array(array('foo', 'bar', 'bat' => array('baz'))),
            array((object) array('foo' => 'bar', 'bat' => 'baz')),
        );
    }
}