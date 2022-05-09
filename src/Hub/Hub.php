<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use InvalidArgumentException;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_key_exists;
use function sprintf;

final class Hub implements HubInterface
{
    public const DEFAULT_OPTIONS = [
        'allow_anonymous' => true,
        'enable_subscription_events' => true,
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
        private EventEmitterInterface $eventEmitter = new EventEmitter(),
        array $options = [],
        iterable $controllers = [],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(self::DEFAULT_OPTIONS);
        $resolver->setAllowedTypes('allow_anonymous', 'bool');
        $resolver->setAllowedTypes('enable_subscription_events', 'bool');
        $this->options = $resolver->resolve($options);
        foreach ($controllers as $controller) {
            $controller->setHub($this);
            $method = $controller->getMethod();
            $route = $controller->getRoute();
            $this->app->{$method}($route, $controller);
        }

        if (true === $this->getOption('enable_subscription_events')) {
            $eventEmitter->on('subscribe', fn(Subscriber $subscriber) => $this->notify($subscriber));
            $eventEmitter->on('unsubscribe', fn(Subscriber $subscriber) => $this->notify($subscriber));
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
        $this->eventEmitter->emit('publish', [$update]);
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
        $this->eventEmitter->emit('subscribe', [$subscriber]);
        $this->transport->subscribe($subscriber);
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $subscriber->active = false;
        $this->eventEmitter->emit('unsubscribe', [$subscriber]);
        $this->transport->unsubscribe($subscriber);
    }

    private function notify(Subscriber $subscriber): void
    {
        foreach ($subscriber->subscriptions as $subscription) {
            $update = new Update(
                $subscription->id,
                new Message(data: (string) json_encode($subscription), private: true)
            );
            $this->publish($update);
        }
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
}
