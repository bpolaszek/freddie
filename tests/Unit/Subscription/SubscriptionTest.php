<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Subscription;

use Freddie\Matcher\TopicMatcher;
use Freddie\Subscription\Subscriber;

it('describes subscriptions', function () {
    $subscriber = new Subscriber([TopicMatcher::urlPattern('https://example.com/books/:id')], id: 'urn:uuid:1');
    $subscription = $subscriber->subscriptions[0];

    $id = '/.well-known/mercure/subscriptions/urlpattern/https%3A%2F%2Fexample.com%2Fbooks%2F%3Aid/urn%3Auuid%3A1';

    expect($subscription->getId())->toBe($id)
        ->and($subscription->toJson(false))->toBe(
            '{"id":"' . $id . '",'
            . '"type":"subscription","subscriber":"urn:uuid:1","match":"https://example.com/books/:id",'
            . '"match_type":"urlpattern","active":false}'
        );
});

it('keeps the protocol version 8 shape for legacy subscriptions', function () {
    $subscriber = new Subscriber([TopicMatcher::legacy('/books/{id}')], id: 'urn:uuid:1');
    $subscription = $subscriber->subscriptions[0];

    expect($subscription->getId())->toBe('/.well-known/mercure/subscriptions/%2Fbooks%2F%7Bid%7D/urn%3Auuid%3A1')
        ->and($subscription->toArray(true))->toBe([
            'id' => '/.well-known/mercure/subscriptions/%2Fbooks%2F%7Bid%7D/urn%3Auuid%3A1',
            'type' => 'subscription',
            'subscriber' => 'urn:uuid:1',
            'topic' => '/books/{id}',
            'active' => true,
        ]);
});

it('generates subscriber identifiers', function () {
    expect((new Subscriber([TopicMatcher::exact('/foo')]))->id)
        ->toMatch('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});
