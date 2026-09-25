<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration\Hub\Transport\Redis;

use ArrayObject;
use Clue\React\Redis\Factory;
use Freddie\Hub\Transport\Redis\RedisSerializer;
use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Hub\Transport\Redis\RedisTransportFactory;
use Freddie\Message\Message;
use Freddie\Message\Update;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function array_fill;
use function count;
use function gc_collect_cycles;
use function getenv;
use function iterator_to_array;
use function microtime;
use function React\Async\async;
use function str_repeat;

/**
 * Drives a RedisTransport against the real Redis given by FREDDIE_TEST_REDIS_DSN.
 */
final class RedisHarness
{
    public const string BACKLOG_KEY = 'freddie_watchdog_test';
    public const int BACKLOG_SIZE = 30000;

    public static function dsn(): ?string
    {
        return getenv('FREDDIE_TEST_REDIS_DSN') ?: null;
    }

    /**
     * Sends one command on a connection of its own, without waiting: safe from a loop callback.
     */
    public static function command(LoopInterface $loop, string $command, string ...$args): void
    {
        $client = (new Factory($loop))->createLazyClient((string) self::dsn());
        $client->{$command}(...$args)->then(fn () => $client->close());
    }

    /**
     * Runs the loop until the promise settles: a top-level await() would reuse the fiber scheduler
     * react/async bound to the loop of a previous test.
     */
    public static function settle(LoopInterface $loop, PromiseInterface $promise): mixed
    {
        $settled = false;
        $result = null;
        $promise->then(
            function ($value) use (&$result, &$settled, $loop) {
                [$result, $settled] = [$value, true];
                $loop->stop();
            },
            function (Throwable $e) use (&$result, &$settled, $loop) {
                [$result, $settled] = [$e, true];
                $loop->stop();
            },
        );
        if (!$settled) {
            $loop->run();
        }
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * Stores BACKLOG_SIZE updates of 1 KB each, all with the id "m".
     */
    public static function fillBacklog(LoopInterface $loop): void
    {
        $client = (new Factory($loop))->createLazyClient((string) self::dsn());
        $update = new Update(['/x'], new Message(id: 'm', data: str_repeat('x', 1000)));
        $payload = (new RedisSerializer())->serialize($update);
        $filled = $client->del(self::BACKLOG_KEY);
        for ($i = 0; $i < self::BACKLOG_SIZE; $i += 500) {
            $batch = array_fill(0, 500, $payload);
            $filled = $filled->then(fn () => $client->rpush(self::BACKLOG_KEY, ...$batch));
        }
        self::settle($loop, $filled);
        $client->close();
    }

    public static function createTransport(LoopInterface $loop, string $query): RedisTransport
    {
        $dsn = self::dsn() . '?key=' . self::BACKLOG_KEY . '&channel=freddie_watchdog_test'
            . '&size=' . self::BACKLOG_SIZE . '&' . $query;

        /** @var RedisTransport */
        return (new RedisTransportFactory(new Factory($loop)))->create($dsn);
    }

    /**
     * What a subscriber reconnecting with "Last-Event-ID: m" triggers.
     *
     * @return PromiseInterface<int>
     */
    public static function reconciliate(RedisTransport $transport): PromiseInterface
    {
        return async(fn () => count(iterator_to_array($transport->reconciliate('m'), false)))();
    }

    /**
     * Runs the loop until something stops it or $seconds elapse, and tells whether Hub::die() ended it.
     *
     * @param ArrayObject<int, Throwable> $rejections
     * @return array{elapsed: float, died: class-string<Throwable>|null, message: string|null}
     */
    public static function runHub(LoopInterface $loop, float $seconds, ArrayObject $rejections): array
    {
        $guard = $loop->addTimer($seconds, fn () => $loop->stop());
        $start = microtime(true);
        $died = null;
        try {
            $loop->run();
        } catch (Throwable $e) {
            $died = $e; // Hub::die() thrown from an event handler
        }
        $loop->cancelTimer($guard);
        gc_collect_cycles();
        $died ??= $rejections[0] ?? null; // Hub::die() thrown from a promise handler

        return [
            'elapsed' => microtime(true) - $start,
            'died' => null === $died ? null : $died::class,
            'message' => $died?->getMessage(),
        ];
    }
}
