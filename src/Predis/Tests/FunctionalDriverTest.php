<?php

namespace Bernard\Driver\Predis\Tests;

use Bernard\Driver\Predis\Driver;
use Predis\Client;

/**
 * @group    functional
 * @requires extension redis
 */
final class FunctionalDriverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Client
     */
    private $redis;

    /**
     * @var Driver
     */
    private $driver;

    public function setUp()
    {
        $this->redis = new Client(
            sprintf('tcp://%s:%s', $_ENV['REDIS_HOST'], $_ENV['REDIS_PORT']),
            [
                'prefix' => 'bernard:',
            ]
        );

        $this->driver = new Driver($this->redis);
    }

    public function tearDown()
    {
        $queues = $this->redis->smembers('queues');

        foreach ($queues as $queue) {
            $this->redis->del('queue:' . $queue);
        }

        $this->redis->del('queues');
    }

    /**
     * @test
     */
    public function it_lists_queues()
    {
        $queues = [
            'failed',
            'queue1',
        ];

        foreach ($queues as $queue) {
            $this->redis->sadd('queues', $queue);
        }

        $queues = $this->driver->listQueues();

        $this->assertContains('failed', $queues);
        $this->assertContains('queue1', $queues);
    }

    /**
     * @test
     */
    public function it_creates_a_queue()
    {
        $this->driver->createQueue('send-newsletter');

        $queues = $this->redis->smembers('queues');

        $this->assertContains('send-newsletter', $queues);
    }

    /**
     * @test
     */
    public function it_counts_the_number_of_messages_in_a_queue()
    {
        $this->redis->sadd('queues', 'send-newsletter');
        $this->redis->rpush('queue:send-newsletter', 'This is a message');
        $this->redis->rpush('queue:send-newsletter', 'This is a message');
        $this->redis->rpush('queue:send-newsletter', 'This is a message');
        $this->redis->rpush('queue:send-newsletter', 'This is a message');

        $this->assertEquals(4, $this->driver->countMessages('send-newsletter'));
    }

    /**
     * @test
     */
    public function it_pushes_a_message()
    {
        $this->redis->sadd('queues', 'send-newsletter');

        $this->driver->pushMessage('send-newsletter', 'This is a message');

        $message = $this->redis->blPop(['queue:send-newsletter'], 5);

        $this->assertEquals(['bernard:queue:send-newsletter', 'This is a message'], $message);
    }

    /**
     * @test
     */
    public function it_pop_messages()
    {
        $this->redis->sadd('queues', 'send-newsletter');
        $this->redis->sadd('queues', 'ask-forgiveness');
        $this->redis->rpush('queue:send-newsletter', 'message1');
        $this->redis->rpush('queue:ask-forgiveness', 'message2');

        $this->assertEquals(['message1', null], $this->driver->popMessage('send-newsletter'));
        $this->assertEquals(['message2', null], $this->driver->popMessage('ask-forgiveness', 30));
    }

    /**
     * @test
     */
    public function it_peeks_in_a_queue()
    {
        $this->redis->sadd('queues', 'my-queue');
        $this->redis->sadd('queues', 'send-newsletter');
        $this->redis->rpush('queue:my-queue', 'message5');
        $this->redis->rpush('queue:my-queue', 'message4');
        $this->redis->rpush('queue:my-queue', 'message3');
        $this->redis->rpush('queue:my-queue', 'message2');
        $this->redis->rpush('queue:my-queue', 'message1');
        $this->redis->rpush('queue:send-newsletter', 'message2');

        $this->assertEquals(['message1'], $this->driver->peekQueue('my-queue', 4, 10));
        $this->assertEquals(['message2'], $this->driver->peekQueue('send-newsletter'));
    }

    /**
     * @test
     */
    public function it_removes_a_queue()
    {
        $this->redis->sadd('queues', 'name');
        $this->redis->rpush('queue:name', 'message1');

        $this->driver->removeQueue('name');

        $this->assertNull($this->redis->get('queue:name'));
        $this->assertNotContains('name', $this->redis->smembers('queues'));
    }
}
