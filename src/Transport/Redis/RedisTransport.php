<?php

namespace Freddie\Transport\Redis;

use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Freddie\Transport\TransportInterface;
use Generator;
use Nyholm\Dsn\Configuration\Dsn;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Ulid;

use function array_column;
use function array_filter;
use function array_find;
use function array_map;
use function Freddie\fromUrn;
use function Freddie\topic;
use function is_numeric;
use function sprintf;
use function substr;
use function Symfony\Component\String\u;
use function urldecode;
use function usleep;

use const PHP_INT_MAX;

final readonly class RedisTransport implements TransportInterface
{
    private const array DEFAULT_OPTIONS = [
        'stream' => 'freddie',
        'size' => 1000,
        'maxBufferedItemsPerStream' => 1000,
        'blockDurationMs' => 5000,
        'sleepDurationMs' => 100,
        'maxIterations' => PHP_INT_MAX,
    ];

    /**
     * @codingStandardsIgnoreStart
     * @var array{stream: string, size: int, maxBufferedItemsPerStream: int, blockDurationMs: int, sleepDurationMs: int, maxIterations: int}
     * @codingStandardsIgnoreEnd
     */
    private array $options;

    /**
     * @codingStandardsIgnoreStart
     * @param array{stream?: string, size?: int, maxBufferedItemsPerStream?: int, blockDurationMs?: int, sleepDurationMs?: int, maxIterations?: int} $options
     * @codingStandardsIgnoreEnd
     */
    public function __construct(
        private LazyRedis $lazyRedis,
        private SerializerInterface $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
        array $options = [],
    ) {
        $resolver = new OptionsResolver();
        $this->options = $resolver
            ->setDefaults(self::DEFAULT_OPTIONS)
            ->setAllowedTypes('size', 'int')
            ->setAllowedTypes('maxBufferedItemsPerStream', 'int')
            ->setAllowedTypes('blockDurationMs', 'int')
            ->setAllowedTypes('sleepDurationMs', 'int')
            ->setAllowedTypes('maxIterations', 'int')
            ->setIgnoreUndefined()
            ->resolve($options);
    }

    public function withOptionsFromDsn(Dsn $parsedDsn): self
    {
        $options = array_filter($parsedDsn->getParameters(), fn (mixed $value) => null !== $value);
        $options = array_map(fn (mixed $value) => is_numeric($value) ? (int) $value : $value, $options);

        return new self($this->lazyRedis, $this->serializer, [...$this->options, ...$options]);
    }

    public function push(Update $update): void
    {
        $redisId = self::ulidToRedisId($update->message->id);
        $redis = $this->lazyRedis->redis;
        $redis->xadd(
            $this->options['stream'],
            $redisId,
            [
                (string) $update->message->id,
                $this->serializer->serialize($update, 'json'),
            ],
            $this->options['size'],
            true,
        );
        $redis->set(
            sprintf('%s:lastEventID', $this->options['stream']),
            (string) $update->message->id,
        );
    }

    public function listen(?string $lastEventId = null, int $iteration = 1): Generator
    {
        $lastEventId ??= '$';
        if ('earliest' === $lastEventId) {
            $lastEventId = 0;
        }

        $streams = $this->lazyRedis->redis->xRead(
            [$this->options['stream'] => $lastEventId],
            $this->options['maxBufferedItemsPerStream'],
            $this->options['blockDurationMs'],
        ) ?: [];

        foreach ($streams[$this->options['stream']] ?? [] as $redisId => [$updateId, $payload]) {
            $lastEventId = $redisId;
            $update = $this->serializer->deserialize(
                $payload,
                Update::class,
                'json',
            );
            yield $update;
        }

        if ($iteration < $this->options['maxIterations']) {
            usleep($this->options['sleepDurationMs'] * 1000);
            yield from $this->listen($lastEventId, ++$iteration);
        }
    }

    public function getLastEventId(): ?Ulid
    {
        $lastEventId = $this->lazyRedis->redis->get(sprintf('%s:lastEventID', $this->options['stream']));
        if (null === $lastEventId) {
            return null;
        }

        return new Ulid($lastEventId);
    }

    public function registerSubscriber(Subscriber $subscriber): void
    {
        $redis = $this->lazyRedis->redis;
        $indexKey = sprintf('%s:subscriber-index', $this->options['stream']);
        $subscriberKey = sprintf('%s:subscriber:%s', $this->options['stream'], $subscriber->id);
        $redis->sAdd($indexKey, (string) $subscriber->id);
        $redis->hMset($subscriberKey, $subscriber->jsonSerialize());
    }

    public function unregisterSubscriber(Subscriber $subscriber): void
    {
        $redis = $this->lazyRedis->redis;
        $indexKey = sprintf('%s:subscriber-index', $this->options['stream']);
        $subscriberKey = sprintf('%s:subscriber:%s', $this->options['stream'], $subscriber->id);
        $redis->srem($indexKey, (string) $subscriber->id);
        $redis->del($subscriberKey);
    }

    public function getSubscriptions(?string $topic): Generator
    {
        $indexKey = sprintf('%s:subscriber-index', $this->options['stream']);
        $subscriberIds = $this->lazyRedis->redis->sMembers($indexKey) ?: [];

        foreach ($subscriberIds as $subscriberId) {
            $subscriberKey = sprintf('%s:subscriber:%s', $this->options['stream'], $subscriberId);
            $data = $this->lazyRedis->redis->hGetAll($subscriberKey);
            if (empty($data)) {
                continue; // @codeCoverageIgnore
            }

            $subscriber = new Subscriber(
                array_column($data['subscriptions'], 'topic'),
                id: new Ulid($data['id']),
            );
            $subscriptions = null === $topic ? $subscriber->subscriptions : array_filter(
                $subscriber->subscriptions,
                function (Subscription $subscription) use ($topic) {
                    return topic($topic)->match([$subscription->topic]);
                },
            );
            foreach ($subscriptions as $subscription) {
                yield $subscription;
            }
        }
    }

    public function getSubscription(string $subscriptionIri): ?Subscription
    {
        $urn = urldecode((string) u($subscriptionIri)->afterLast('/'));
        $subscriberId = fromUrn($urn);
        $subscriberKey = sprintf('%s:subscriber:%s', $this->options['stream'], $subscriberId);

        $data = $this->lazyRedis->redis->hGetAll($subscriberKey);
        if (empty($data)) {
            return null;
        }
        $subscriber = new Subscriber(
            array_column($data['subscriptions'], 'topic'),
            id: new Ulid($data['id']),
        );

        return array_find(
            $subscriber->subscriptions,
            fn (Subscription $subscription) => $subscription->id === $subscriptionIri,
        );
    }

    private static function ulidToRedisId(Ulid $ulid): string
    {
        $dateTime = $ulid->getDateTime();

        return $dateTime->format('Uv') . '-' . (int) substr($dateTime->format('u'), 3, 3);
    }
}
