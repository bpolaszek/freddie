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
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Throwable;

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
        $this->transport->subscribe($subscriber);
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $this->transport->unsubscribe($subscriber);
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

    public static function die(Throwable $e): never
    {
        Loop::stop();

        throw $e;
    }
}
