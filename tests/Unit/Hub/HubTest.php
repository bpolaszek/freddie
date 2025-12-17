<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub;

use Freddie\Hub\Hub;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Tests\Mock\PHPTransport;
use Symfony\Component\Uid\Ulid;

use function expect;
use function Freddie\urn;
use function it;
use function urlencode;

it('is instantiated with default options', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport);

    expect($hub->options['allow_anonymous'])->toBeTrue();
});

it('is instantiated with custom options', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport, ['allow_anonymous' => false]);

    expect($hub->options['allow_anonymous'])->toBeFalse();
});

it('throws exception when getting non-existent option', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport);

    expect($hub->options['non_existent'] ?? null)->toBeNull(); // @phpstan-ignore nullCoalesce.offset
});

it('publishes update to transport', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport);

    $message = new Message(new Ulid(), 'test data');
    $update = new Update('topic1', $message);

    $hub->publish($update);

    expect($transport->updates)->toHaveCount(1)
        ->and($transport->updates[0])->toBe($update);
});

it('subscribes and yields updates for matching topics', function () {
    $message1 = new Message(new Ulid(), 'data1');
    $message2 = new Message(new Ulid(), 'data2');
    $message3 = new Message(new Ulid(), 'data3');

    $update1 = new Update('topic1', $message1);
    $update2 = new Update('topic2', $message2);
    $update3 = new Update(['topic3', 'topic4'], $message3);

    $transport = new PHPTransport([$update1, $update2, $update3]);
    $hub = new Hub($transport);

    // Subscribe to all topics (default '*')
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(lastEventId: 'earliest')));
    expect($updates)->toHaveCount(3)
        ->and($updates)->toContain($update1, $update2, $update3);

    // Subscribe to specific topic
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['topic1'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toBe($update1);

    // Subscribe to multiple topics
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['topic1', 'topic2'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(2)
        ->and($updates)->toContain($update1, $update2);
});

it('filters updates based on lastEventId', function () {
    $id1 = new Ulid();
    $id2 = new Ulid();
    $id3 = new Ulid();

    $message1 = new Message($id1, 'data1');
    $message2 = new Message($id2, 'data2');
    $message3 = new Message($id3, 'data3');

    $update1 = new Update('topic1', $message1);
    $update2 = new Update('topic2', $message2);
    $update3 = new Update('topic3', $message3);

    $transport = new PHPTransport([$update1, $update2, $update3]);
    $hub = new Hub($transport);

    // Get updates after id1
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['*'], null, (string) $id1)));
    expect($updates)->toHaveCount(2)
        ->and($updates)->toContain($update2, $update3);

    // Get updates after id2
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['*'], null, (string) $id2)));
    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toBe($update3);

    // Get all updates with 'earliest'
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['*'], null, 'earliest')));
    expect($updates)->toHaveCount(3);

    // Gets further updates only when lastEventId is null
    $updates = iterator_to_array($hub->getUpdates(new Subscriber()));
    expect($updates)->toBeEmpty();
});

it('filters private updates when anonymous access is disabled', function () {
    $message1 = new Message(new Ulid(), 'public data');
    $message2 = new Message(new Ulid(), 'private data', true);

    $update1 = new Update('topic1', $message1);
    $update2 = new Update('topic2', $message2);

    $transport = new PHPTransport([$update1, $update2]);
    $hub = new Hub($transport, ['allow_anonymous' => false]);

    // Without allowed topics, private messages are filtered out
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(lastEventId: 'earliest')));
    expect($updates)->toHaveCount(0);

    // With allowed topics, private messages for those topics are included
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(allowedTopics: ['topic2'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(2)
        ->and($updates)->toContain($update1, $update2);
});

it('handles URI templates in topic matching', function () {
    $message1 = new Message(new Ulid(), 'data1');
    $message2 = new Message(new Ulid(), 'data2');

    $update1 = new Update('/books/1', $message1);
    $update2 = new Update('/authors/2', $message2);

    $transport = new PHPTransport([$update1, $update2]);
    $hub = new Hub($transport);

    // Subscribe with URI template
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['/books/{id}'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toBe($update1);
});

it('handles updates with multiple topics', function () {
    $message = new Message(new Ulid(), 'multi-topic data');
    $update = new Update(['topic1', 'topic2', 'topic3'], $message);

    $transport = new PHPTransport([$update]);
    $hub = new Hub($transport);

    // Should match if any topic matches
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['topic2'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toBe($update);
});

it('properly filters based on allowed topics for private updates', function () {
    $message = new Message(new Ulid(), 'private data', true);
    $update = new Update(['topic1', 'topic2'], $message);

    $transport = new PHPTransport([$update]);
    $hub = new Hub($transport);

    // Private update with matching allowed topic
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['topic1'], ['topic1'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(1);

    // Private update with non-matching allowed topic
    $updates = iterator_to_array($hub->getUpdates(new Subscriber(['topic1'], ['topic3'], lastEventId: 'earliest')));
    expect($updates)->toHaveCount(0);
});

it('publishes new subscriptions when subscriptions are enabled', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport, ['subscriptions' => true]);
    $subscriber = new Subscriber(['/books/{id}', '/authors/{id}'], payload: 'user123');
    $initialSubscriber = clone $subscriber;
    $hub->subscribe($subscriber);
    $hub->publish(new Update('/books/1', new Message(data: 'book')));
    $hub->unsubscribe($subscriber);

    $expectedTopic1 = '/.well-known/mercure/subscriptions/%2Fbooks%2F%7Bid%7D/' . urlencode(urn($subscriber->id));
    $expectedTopic2 = '/.well-known/mercure/subscriptions/%2Fauthors%2F%7Bid%7D/' . urlencode(urn($subscriber->id));
    expect($transport->updates)->toHaveCount(5)
        ->and($transport->updates[0]->topics)->toBe([$expectedTopic1])
        ->and($transport->updates[0]->message->data)->toBe((string) $initialSubscriber->subscriptions[0])
        ->and($transport->updates[1]->topics)->toBe([$expectedTopic2])
        ->and($transport->updates[1]->message->data)->toBe((string) $initialSubscriber->subscriptions[1])
        ->and($transport->updates[2]->topics)->toBe(['/books/1'])
        ->and($transport->updates[2]->message->data)->toBe('book')
        ->and($transport->updates[3]->topics)->toBe([$expectedTopic1])
        ->and($transport->updates[3]->message->data)->toBe((string) $subscriber->subscriptions[0])
        ->and($transport->updates[4]->topics)->toBe([$expectedTopic2])
        ->and($transport->updates[4]->message->data)->toBe((string) $subscriber->subscriptions[1])
    ;
});

it('does not propagate subscriptions when they are disabled', function () {
    $transport = new PHPTransport();
    $hub = new Hub($transport, ['subscriptions' => false]);
    $subscriber = new Subscriber(['/books/{id}', '/authors/{id}'], payload: 'user123');
    $hub->subscribe($subscriber);
    $hub->publish(new Update('/books/1', new Message(data: 'book')));
    $hub->unsubscribe($subscriber);

    $expectedTopic1 = '/.well-known/mercure/subscriptions/%2Fbooks%2F%7Bid%7D/' . urlencode(urn($subscriber->id));
    $expectedTopic2 = '/.well-known/mercure/subscriptions/%2Fauthors%2F%7Bid%7D/' . urlencode(urn($subscriber->id));
    expect($transport->updates)->toHaveCount(1)
        ->and($transport->updates[0]->topics)->toBe(['/books/1'])
        ->and($transport->updates[0]->message->data)->toBe('book')
    ;
});
