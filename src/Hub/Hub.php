<?php

declare(strict_types=1);

namespace Freddie\Hub;

use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use InvalidArgumentException;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SplObjectStorage;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Throwable;

use function array_key_exists;
use function iterator_to_array;
use function sprintf;

final class Hub implements HubInterface
{
    /**
     * The protocol version the compatibility mode emulates (Mercure 0.x).
     */
    public const int LEGACY_PROTOCOL_VERSION = 8;

    public const DEFAULT_OPTIONS = [
        'allow_anonymous' => true,
        'heartbeat_interval' => 40.0,
        'protocol_compatibility' => null,
        'subscriptions' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $options;

    private bool $started = false;

    /**
     * Subscribers connected to this process.
     *
     * @var SplObjectStorage<Subscriber, null>
     */
    private SplObjectStorage $subscribers;

    /**
     * @codeCoverageIgnore
     * @param array<string, mixed> $options
     * @param iterable<HubControllerInterface> $controllers
     */
    public function __construct(
        private App $app = new App(new HttpExceptionConverterMiddleware()),
        private TransportInterface $transport = new PHPTransport(),
        array $options = [],
        iterable $controllers = [],
        private TopicMatcherStore $topicMatcherStore = new TopicMatcherStore(),
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(self::DEFAULT_OPTIONS);
        $resolver->setAllowedTypes('allow_anonymous', 'bool');
        $resolver->setAllowedTypes('heartbeat_interval', ['int', 'float']);
        // Environment variables come as strings ("" when unset).
        $resolver->setNormalizer('protocol_compatibility', function (Options $options, mixed $value): ?int {
            $version = null === $value || '' === $value ? null : (int) $value;
            if (null !== $version && self::LEGACY_PROTOCOL_VERSION !== $version) {
                throw new InvalidOptionsException(
                    sprintf('Only the protocol version %d can be emulated.', self::LEGACY_PROTOCOL_VERSION),
                );
            }

            return $version;
        });
        $resolver->setAllowedTypes('subscriptions', 'bool');
        $this->options = $resolver->resolve($options);
        $this->subscribers = new SplObjectStorage();
        foreach ($controllers as $controller) {
            $controller->setHub($this);
            $this->app->map($controller->getMethods(), $controller->getRoute(), $controller);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function run(): void
    {
        $this->started = true;
        $this->app->run();
    }

    public function publish(Update $update): PromiseInterface
    {
        return $this->transport->publish($update)
            ->then(function (Update $update) {
                if (false === $this->started) {
                    Loop::stop();
                }

                return $update;
            });
    }

    public function subscribe(Subscriber $subscriber): void
    {
        $this->subscribers->attach($subscriber);
        $this->transport->subscribe($subscriber);
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $this->subscribers->detach($subscriber);
        $this->transport->unsubscribe($subscriber);
    }

    public function getSubscribers(): array
    {
        return iterator_to_array($this->subscribers, false);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        return $this->transport->reconciliate($lastEventID);
    }

    public function getOption(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new InvalidArgumentException(sprintf('Invalid option `%s`.', $name));
        }

        return $this->options[$name];
    }

    public function isLegacyProtocol(): bool
    {
        return self::LEGACY_PROTOCOL_VERSION === $this->options['protocol_compatibility'];
    }

    public function getTopicMatcherStore(): TopicMatcherStore
    {
        return $this->topicMatcherStore;
    }

    public static function die(Throwable $e): never
    {
        Loop::stop();

        throw $e;
    }
}
