<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration\Hub\Transport\Redis;

use ArrayObject;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Timer\TimeoutException;
use RuntimeException;
use Throwable;

use function gc_collect_cycles;
use function microtime;
use function React\Promise\all;
use function React\Promise\set_rejection_handler;
use function usleep;

/*
 * These tests need a real Redis, given without query string:
 * FREDDIE_TEST_REDIS_DSN=redis://localhost:6379 vendor/bin/pest tests/Integration/Hub/Transport/Redis
 *
 * A subscriber reconnecting with a Last-Event-ID makes reconciliate() LRANGE the whole stored list.
 * clue/redis-protocol re-parses a multi-bulk reply from its start on every TCP chunk, so a list of
 * 30000 updates takes seconds to arrive, and a Redis connection answers in order.
 */

beforeEach(function () {
    $this->previousLoop = Loop::get();
    Loop::set($this->loop = new StreamSelectLoop());
    $this->rejections = $rejections = new ArrayObject();
    // react/promise unsets the handler before calling it, so it must put itself back
    $handler = function (Throwable $e) use (&$handler, $rejections) {
        $rejections[] = $e;
        set_rejection_handler($handler);
    };
    $this->previousHandler = set_rejection_handler($handler);
    if (null === RedisHarness::dsn()) {
        $this->markTestSkipped('FREDDIE_TEST_REDIS_DSN is not set.');
    }
});

afterEach(function () {
    // Drop this test's loop, timers and promises while its rejection handler still listens
    Loop::set($this->previousLoop);
    $this->loop = null;
    gc_collect_cycles();
    set_rejection_handler($this->previousHandler);
});

it('answers the watchdog ping while a reconciliation streams (head-of-line blocking)', function () {
    RedisHarness::fillBacklog($this->loop);
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0');

    // Given a reconciliation in flight
    $reconciled = RedisHarness::reconciliate($transport);
    $pong = null;
    $this->loop->futureTick(function () use ($transport, &$pong) {
        // When the watchdog pings the connection it watches
        $sent = microtime(true);
        $transport->redis->ping()->then(function () use ($sent, &$pong) {
            $pong = microtime(true) - $sent;
        });
    });
    $reconciled->then(fn () => $this->loop->stop());
    RedisHarness::runHub($this->loop, 60, $this->rejections);

    // Then the PONG does not wait for the LRANGE reply
    expect($pong)->not->toBeNull();
    expect($pong)->toBeLessThan(1.0);
});

it('does not take a busy but healthy Redis connection for a dead one', function () {
    RedisHarness::fillBacklog($this->loop);
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0.2&readTimeout=1');

    // When 2 subscribers reconnect at once with a Last-Event-ID
    $counts = null;
    $reconciliations = [RedisHarness::reconciliate($transport), RedisHarness::reconciliate($transport)];
    all($reconciliations)->then(function (array $result) use (&$counts) {
        $counts = $result;
        $this->loop->stop();
    });
    $run = RedisHarness::runHub($this->loop, 60, $this->rejections);

    // Then the watchdog never fired and both reconciliations completed
    expect($run['died'])->toBeNull();
    expect($counts)->toBe([RedisHarness::BACKLOG_SIZE - 1, RedisHarness::BACKLOG_SIZE - 1]);
});

it('still ends the hub when Redis stops answering (watchdog timeout)', function () {
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0.2&readTimeout=0.5');
    $transport->subscribe(fn () => null);

    // When Redis stops answering every client
    $this->loop->addTimer(0.5, fn () => RedisHarness::command($this->loop, 'client', 'PAUSE', '1500'));
    $run = RedisHarness::runHub($this->loop, 5, $this->rejections);
    usleep(1_500_000); // let the pause expire, loop stopped, before the next test

    // Then the watchdog ends the hub within pingInterval + readTimeout
    expect($run['died'])->toBe(TimeoutException::class);
    expect($run['elapsed'])->toBeLessThan(0.5 + 0.2 + 0.5 + 0.3);
});

it('still ends the hub when Redis closes the subscription connection (connection lost)', function () {
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0.2&readTimeout=0.5');
    $transport->subscribe(fn () => null);

    // When Redis drops the subscription connection
    $this->loop->addTimer(0.5, fn () => RedisHarness::command($this->loop, 'client', 'KILL', 'TYPE', 'pubsub'));
    $run = RedisHarness::runHub($this->loop, 5, $this->rejections);

    // Then the hub ends right away
    expect($run['died'])->toBe(RuntimeException::class);
    expect($run['message'])->toBe('Redis connection lost');
    expect($run['elapsed'])->toBeLessThan(1.0);
});

it('keeps pinging a subscribed connection without false alarm (subscriber ping path)', function () {
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0.1&readTimeout=0.5');
    $transport->subscribe(fn () => null);

    // When the watchdog pings the subscribed connection for 2 seconds
    $run = RedisHarness::runHub($this->loop, 2, $this->rejections);

    // Then its ["pong", ""] replies are matched and nothing dies
    expect($run['died'])->toBeNull();
    $pong = RedisHarness::settle($this->loop, $transport->subscriber->ping());
    expect($pong)->toBe(['pong', '']);
});

it('does not watch Redis when pingInterval is 0', function () {
    $transport = RedisHarness::createTransport($this->loop, 'pingInterval=0&readTimeout=0.5');
    $transport->subscribe(fn () => null);

    // When Redis stops answering
    $this->loop->addTimer(0.2, fn () => RedisHarness::command($this->loop, 'client', 'PAUSE', '1500'));
    $run = RedisHarness::runHub($this->loop, 1.2, $this->rejections);
    usleep(600_000); // let the pause expire, loop stopped, before the next test

    // Then nothing ends the hub
    expect($run['died'])->toBeNull();
});
