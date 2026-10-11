<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub;

use Freddie\Hub\Hub;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use InvalidArgumentException;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

use function func_get_args;
use function iterator_to_array;
use function React\Promise\resolve;

it('exposes its transport methods', function () {
    $transport = new class () implements TransportInterface {
        public array $called = [];
        public function publish(Update $update): PromiseInterface
        {
            $this->called['publish'] = func_get_args();

            return resolve($update);
        }

        public function subscribe(callable $callback): void
        {
            $this->called['subscribe'] = func_get_args();
        }

        public function unsubscribe(callable $callback): void
        {
            $this->called['unsubscribe'] = func_get_args();
        }

        public function reconciliate(string $lastEventID): Generator
        {
            $this->called['reconciliate'] = func_get_args();
            yield;
        }
    };

    // Given
    $hub = new Hub(transport: $transport);
    $update = new Update(['foo'], new Message(Ulid::generate()));
    $subscribeFn = fn () => 'bar';
    $subscriber = new Subscriber([TopicMatcher::exact('foo')]);
    $subscriber->setCallback($subscribeFn);
    $lastEventId = Ulid::generate();

    // When
    $hub->publish($update);
    $hub->subscribe($subscriber);
    iterator_to_array($hub->reconciliate($lastEventId));
    $subscribers = $hub->getSubscribers();
    $hub->unsubscribe($subscriber);

    // Then
    expect($transport->called['publish'])->toBe([$update]);
    expect($transport->called['subscribe'])->toBe([$subscriber]);
    expect($transport->called['reconciliate'])->toBe([$lastEventId]);
    expect($transport->called['unsubscribe'])->toBe([$subscriber]);
    expect($subscribers)->toBe([$subscriber]);
    expect($hub->getSubscribers())->toBe([]);
});

it('runs in compatibility mode on demand', function () {
    $store = new TopicMatcherStore();

    expect((new Hub())->isLegacyProtocol())->toBeFalse()
        ->and((new Hub(options: ['protocol_compatibility' => 8]))->isLegacyProtocol())->toBeTrue()
        ->and((new Hub(options: ['protocol_compatibility' => '8']))->isLegacyProtocol())->toBeTrue()
        ->and((new Hub(options: ['protocol_compatibility' => '']))->isLegacyProtocol())->toBeFalse()
        ->and((new Hub(topicMatcherStore: $store))->getTopicMatcherStore())->toBe($store);
});

it('only emulates the protocol version 8', function () {
    new Hub(options: ['protocol_compatibility' => 7]);
})->throws(InvalidArgumentException::class);

it('complains when requesting an unrecognized option', function () {
    $hub = new Hub();
    $hub->getOption('foo');
})->throws(InvalidArgumentException::class, 'Invalid option `foo`.');

it('dies', function () {
    Hub::die(new RuntimeException('😵'));
})->throws(RuntimeException::class, '😵');
