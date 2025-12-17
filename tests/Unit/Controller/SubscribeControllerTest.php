<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Controller;

use Freddie\Controller\SubscribeController;
use Freddie\Hub\Hub;
use Freddie\Hub\HubInterface;
use Freddie\Hub\HubOptions;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Freddie\Tests\Mock\PHPTransport;
use Freddie\Tests\Mock\ServerEventFactoryStub;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;

use function assert;
use function expect;
use function Freddie\Tests\createJWT;
use function Freddie\Tests\createSfRequest;
use function it;
use function ob_end_clean;
use function ob_start;
use function strtr;
use function trim;

it('sends updates as Server Events', function () {
    $updates = [
        new Update('foos', new Message(data: 'foo')),
        new Update('bars', new Message(data: 'bar')),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $hub = new Hub(new PHPTransport($updates), options: ['allow_anonymous' => true]);
    $serverEvents = new ServerEventFactoryStub();
    $controller = new SubscribeController($hub, factory: $serverEvents, debug: true);
    $request = createSfRequest('GET', '/.well-known/mercure?topic=*&lastEventID=earliest');
    $response = $controller->subscribe($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/event-stream');

    $extraUpdate = new Update('example', new Message(data: 'baz'));
    $hub->publish($extraUpdate);

    ob_start();
    $response->send();
    ob_end_clean();

    expect($serverEvents->updates)->toBe([...$updates, $extraUpdate]);
});

it('throws BadRequestHttpException when no topic is provided', function () {
    $hub = new Hub(new PHPTransport(), options: ['allow_anonymous' => true]);
    $controller = new SubscribeController($hub, debug: true);
    $request = createSfRequest('GET', '/.well-known/mercure');

    $response = $controller->subscribe($request);
    ob_start();
    $response->send();
    ob_end_clean();
})->throws(BadRequestHttpException::class, 'Missing topic parameter.');

it('throws AccessDeniedHttpException when anonymous subscriptions are not allowed and no JWT is provided', function () {
    $hub = new Hub(new PHPTransport(), options: ['allow_anonymous' => false]);
    $controller = new SubscribeController($hub, debug: true);
    $request = createSfRequest('GET', '/.well-known/mercure?topic=*');

    $response = $controller->subscribe($request);
    ob_start();
    $response->send();
    ob_end_clean();
})->throws(AccessDeniedHttpException::class, 'Anonymous subscriptions are not allowed on this hub.');

it('filters updates by topic', function () {
    $updates = [
        new Update('foos', new Message(data: 'foo')),
        new Update('bars', new Message(data: 'bar')),
        new Update('baz', new Message(data: 'baz')),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $hub = new Hub(new PHPTransport($updates), options: ['allow_anonymous' => true]);
    $serverEvents = new ServerEventFactoryStub();
    $controller = new SubscribeController($hub, factory: $serverEvents, debug: true);
    $request = createSfRequest('GET', '/.well-known/mercure?topic=foos&topic=bars&lastEventID=earliest');
    $response = $controller->subscribe($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/event-stream');

    ob_start();
    $response->send();
    ob_end_clean();

    expect($serverEvents->updates)->toBe([$updates[0], $updates[1]]);
});

it('filters updates by allowed topics', function () {
    $updates = [
        new Update('foos', new Message(data: 'foo', private: true, event: 'update')),
        new Update('bars', new Message(data: 'bar', private: true, retry: 2)),
        new Update('baz', new Message(data: 'baz', private: true)),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $hub = new Hub(new PHPTransport($updates), options: ['allow_anonymous' => true]);
    $serverEvents = new ServerEventFactoryStub();
    $controller = new SubscribeController($hub, factory: $serverEvents, debug: true);
    $token = createJWT([
        'mercure' => [
            'subscribe' => ['foos', 'bars'],
        ],
    ]);
    $request = createSfRequest('GET', '/.well-known/mercure?topic=*&lastEventID=earliest', [
        'Authorization' => "Bearer $token"
    ]);
    $response = $controller->subscribe($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/event-stream');

    ob_start();
    $response->send();
    $output = ob_get_clean();

    $expectedOutput = <<<EOF
id: [ID_1]
event: update
data: foo

id: [ID_2]
retry: 2
data: bar
EOF;
    $expectedOutput = strtr($expectedOutput, [
        '[ID_1]' => $updates[0]->message->id,
        '[ID_2]' => $updates[1]->message->id,
    ]);

    expect($serverEvents->updates)->toBe([$updates[0], $updates[1]])
        ->and(trim($output))->toBe($expectedOutput)
    ;
});

it('unsubscribes when connection is aborted', function () {
    $hub = new class implements HubInterface {
        public private(set) array $options = HubOptions::DEFAULTS;
        public private(set) bool $subscribed = true;

        /**
         * @var Update[]
         */
        private array $updates = [];
        public function subscribe(Subscriber $subscriber): void
        {
        }

        public function getUpdates(Subscriber $subscriber): \Generator
        {
            yield from $this->updates;
        }

        public function unsubscribe(Subscriber $subscriber): void
        {
            $this->subscribed = false;
        }

        public function isConnectionAborted(): bool
        {
            return true; // Simulate connection aborted
        }

        public function publish(Update $update): void
        {
            $this->updates[] = $update;
        }

        public function getLastEventId(): ?Ulid
        {
            return null;
        }

        public function getSubscription(string $subscriptionIri): ?Subscription
        {
            return null;
        }

        public function getSubscriptions(?string $topic): iterable
        {
            return [];
        }
    };

    $updates = [
        new Update('foos', new Message(data: 'foo')),
        new Update('bars', new Message(data: 'bar')),
    ];

    $controller = new SubscribeController($hub, debug: true);
    $request = createSfRequest('GET', '/.well-known/mercure?topic=*&lastEventID=earliest');
    foreach ($updates as $update) {
        $hub->publish($update);
    }
    $response = $controller->subscribe($request);

    ob_start();
    $response->send();
    ob_end_clean();

    expect($hub->subscribed)->toBeFalse();
});
