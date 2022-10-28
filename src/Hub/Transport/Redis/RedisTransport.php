<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Clue\React\Redis\Client;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Update;
use Generator;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function React\Async\await;
use function React\Promise\resolve;

final class RedisTransport implements TransportInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $options;
    private bool $initialized = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly Client $subscriber,
        public readonly Client $redis,
        private readonly RedisSerializer $serializer = new RedisSerializer(),
        private readonly EventEmitterInterface $eventEmitter = new EventEmitter(),
        array $options = [],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'size' => 0,
            'trimInterval' => 0.0,
            'channel' => 'mercure',
            'key' => 'mercureUpdates',
        ]);
        $this->options = $resolver->resolve($options);
    }

    public function subscribe(callable $callback): void
    {
        $this->init();
        $this->eventEmitter->on('mercureUpdate', $callback);
    }

    public function unsubscribe(callable $callback): void
    {
        $this->eventEmitter->removeListener('mercureUpdate', $callback);
    }

    public function publish(Update $update): PromiseInterface
    {
        $this->init();
        $payload = $this->serializer->serialize($update);

        return $this->redis->publish($this->options['channel'], $payload) // @phpstan-ignore-line
            ->then(fn () => $this->store($update))
            ->then(fn () => $update);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        $this->init();
        if ($this->options['size'] <= 0) {
            return; // @codeCoverageIgnore
        }

        $yield = self::EARLIEST === $lastEventID;
        // @phpstan-ignore-next-line
        $payloads = await($this->redis->lrange($this->options['key'], -$this->options['size'], -1));
        foreach ($payloads as $payload) {
            $update = $this->serializer->deserialize($payload);
            if ($yield) {
                yield $update;
            }
            if ($update->message->id === $lastEventID) {
                $yield = true;
            }
        }
    }

    private function store(Update $update): PromiseInterface
    {
        $this->init();
        if ($this->options['size'] <= 0) {
            return resolve();
        }

        // @phpstan-ignore-next-line
        return $this->redis->rpush($this->options['key'], $this->serializer->serialize($update));
    }

    private function init(): void
    {
        if (true === $this->initialized) {
            return;
        }

        $this->subscriber->subscribe($this->options['channel']); // @phpstan-ignore-line
        $this->subscriber->on('message', function (string $channel, string $payload) {
            $this->eventEmitter->emit('mercureUpdate', [$this->serializer->deserialize($payload)]);
        });

        if ($this->options['trimInterval'] > 0) {
            Loop::addPeriodicTimer(
                $this->options['trimInterval'],
                fn () => $this->redis->ltrim($this->options['key'], -$this->options['size'], -1) // @phpstan-ignore-line
            );
        }
        $this->initialized = true;
    }
}
