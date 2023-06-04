<?php

declare(strict_types=1);

namespace Freddie\Hub;

use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_key_exists;
use function sprintf;

final class Hub implements HubInterface
{
    public const DEFAULT_OPTIONS = [
        'allow_anonymous' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $options;

    private bool $started = false;

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
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(self::DEFAULT_OPTIONS);
        $resolver->setAllowedTypes('allow_anonymous', 'bool');
        $this->options = $resolver->resolve($options);
        foreach ($controllers as $controller) {
            $controller->setHub($this);
            $method = $controller->getMethod();
            $route = $controller->getRoute();
            $this->app->{$method}($route, $controller);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function run(): void
    {
        $this->started = true;
        $this->logger->info(sprintf('HUB: Started with %s', $this->transport::class));
        $this->app->run();
    }

    public function publish(Update $update): PromiseInterface
    {
        $this->logger->debug(sprintf('HUB: Publish %s', $update));
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
        $this->logger->debug(sprintf('HUB: New subscription %s', $subscriber));
        $this->transport->subscribe($subscriber);
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $this->logger->debug(sprintf('HUB: Unsubscription %s', $subscriber));
        $this->transport->unsubscribe($subscriber);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        return $this->transport->reconciliate($lastEventID);
    }

    public function getOption(string $name): mixed
    {
        if (!array_key_exists($name, $this->options)) {
            throw new InvalidArgumentException(sprintf('HUB: Invalid option `%s`.', $name));
        }

        return $this->options[$name];
    }
}
