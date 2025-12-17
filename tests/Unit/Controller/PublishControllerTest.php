<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Controller;

use Freddie\Controller\PublishController;
use Freddie\Hub\Hub;
use Freddie\Hub\HubInterface;
use Freddie\Hub\HubOptions;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Freddie\Tests\Mock\PHPTransport;
use Generator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Ulid;

use function expect;
use function Freddie\fromUrn;
use function Freddie\Tests\createJWT;
use function Freddie\Tests\createSfRequest;
use function Freddie\Tests\stringifyMessage;
use function it;

it('publishes an update to the hub', function (Message $message) {
    // Given
    $transport = new PHPTransport();
    $hub = new Hub($transport);
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['publish' => ['*']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: "topic=example&" . stringifyMessage($message));
    $response = $controller->publish($request);

    // Then
    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED)
        ->and($response->getContent())->toBeString()
        ->and($response->getContent())->toStartWith('urn:uuid:')
        ->and($transport->updates)->toHaveCount(1)
        ->and($transport->updates[0]->topics)->toBe(['example'])
        ->and($transport->updates[0]->message->id)->toBeInstanceOf(Ulid::class)
        ->and((string) $transport->updates[0]->message->id)->toEqual(fromUrn($response->getContent()))
        ->and($transport->updates[0]->message->data)->toBe($message->data)
        ->and($transport->updates[0]->message->private)->toBe($message->private)
        ->and($transport->updates[0]->message->event)->toBe($message->event)
        ->and($transport->updates[0]->message->retry)->toBe($message->retry);
})->with(function () {
    yield new Message(data: 'foo');
    yield new Message(data: 'bar', private: true);
    yield new Message(data: 'bar', private: false);
    yield new Message(data: 'bar', event: 'test');
    yield new Message(data: 'bar', retry: 10);
    yield new Message(id: new Ulid(), data: 'bar');
    yield new Message(new Ulid(), 'foo', true, 'test', 5);
});

it('throws an exception if no topic is provided', function () {
    // Given
    $hub = new Hub(new PHPTransport());
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['publish' => ['*']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: 'data=example');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(BadRequestHttpException::class, 'Missing topic parameter.');
});

it('throws an exception if provided ID is invalid', function () {
    // Given
    $hub = new Hub(new PHPTransport());
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['publish' => ['*']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: 'topic=example&data=foo&id=123456');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(BadRequestHttpException::class, 'Invalid ID.');
});

it('throws an exception when publishing a private update on unauthorized topics', function () {
    // Given
    $hub = new Hub(new PHPTransport());
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['publish' => ['greetings']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: 'topic=example&data=foo&private=on');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(
            AccessDeniedHttpException::class,
            'Your rights are not sufficient to publish this update.'
        );
});

it('throws a ServiceUnavailableHttpException on transport failure', function () {
    // Given
    $hub = new class implements HubInterface {
        public array $options = HubOptions::DEFAULTS;
        public function subscribe(Subscriber $subscriber): void
        {
        }

        public function getUpdates(Subscriber $subscriber): Generator
        {
            yield; // @phpstan-ignore generator.valueType
        }

        public function unsubscribe(Subscriber $subscriber): void
        {
        }

        public function publish(Update $update): void
        {
            throw new RuntimeException();
        }

        public function isConnectionAborted(): bool
        {
            return false;
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
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['publish' => ['*']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: 'topic=example&data=foo');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(ServiceUnavailableHttpException::class);
});

it('throws an exception when no JWT is provided', function () {
    // Given
    $hub = new Hub(new PHPTransport());
    $controller = new PublishController($hub);

    // When
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], content: 'topic=example&data=foo');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(
            AccessDeniedHttpException::class,
            'You must be authenticated to publish on this hub.'
        );
});

it('throws an exception when provided JWT doesn\'t have required claims', function () {
    // Given
    $hub = new Hub(new PHPTransport());
    $controller = new PublishController($hub);

    // When
    $token = createJWT(['mercure' => ['subscribe' => ['*']]]);
    $request = createSfRequest('POST', '/.well-known/mercure', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => "Bearer $token",
    ], content: 'topic=example&data=foo');

    // Then
    expect(fn () => $controller->publish($request))
        ->toThrow(
            AccessDeniedHttpException::class,
            'Missing mercure.publish claim.'
        );
});
