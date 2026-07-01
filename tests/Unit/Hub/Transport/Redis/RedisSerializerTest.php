<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Freddie\Hub\Transport\Redis\RedisSerializer;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

it('round-trips an update with all message fields', function () {
    $serializer = new RedisSerializer();
    $update = new Update(
        ['/foo', '/bar'],
        new Message(id: '01ARZ3', data: "line1\nline2", private: true, event: 'ping', retry: 3000),
    );

    $result = $serializer->deserialize($serializer->serialize($update));

    expect($result->topics)->toBe(['/foo', '/bar']);
    expect($result->message->id)->toBe('01ARZ3');
    expect($result->message->data)->toBe("line1\nline2");
    expect($result->message->private)->toBeTrue();
    expect($result->message->event)->toBe('ping');
    expect($result->message->retry)->toBe(3000);
});

it('round-trips an update with null message fields and preserves the id', function () {
    $serializer = new RedisSerializer();
    $update = new Update('/foo', new Message(id: '01BX5Z'));

    $result = $serializer->deserialize($serializer->serialize($update));

    expect($result->topics)->toBe(['/foo']);
    expect($result->message->id)->toBe('01BX5Z');
    expect($result->message->data)->toBeNull();
    expect($result->message->private)->toBeFalse();
    expect($result->message->event)->toBeNull();
    expect($result->message->retry)->toBeNull();
});

// Guarantees mixed old/new hubs interoperate during a rolling deploy (Redis
// pub/sub has no format versioning), by cross-checking against the previous
// Symfony ObjectNormalizer wire format.
it('stays wire-compatible with the Symfony ObjectNormalizer format', function () {
    $serializer = new RedisSerializer();
    $objectNormalizer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    $update = new Update(['/foo'], new Message(id: '01CX', data: 'hi', private: true, event: 'e', retry: 1));

    // new serialize -> old deserialize
    /** @var Update $fromNew */
    $fromNew = $objectNormalizer->deserialize($serializer->serialize($update), Update::class, 'json');
    expect($fromNew->topics)->toBe(['/foo']);
    expect($fromNew->message->id)->toBe('01CX');
    expect($fromNew->message->data)->toBe('hi');
    expect($fromNew->message->private)->toBeTrue();
    expect($fromNew->message->event)->toBe('e');
    expect($fromNew->message->retry)->toBe(1);

    // old serialize -> new deserialize
    $fromOld = $serializer->deserialize($objectNormalizer->serialize($update, 'json'));
    expect($fromOld->topics)->toBe(['/foo']);
    expect($fromOld->message->id)->toBe('01CX');
    expect($fromOld->message->data)->toBe('hi');
    expect($fromOld->message->private)->toBeTrue();
    expect($fromOld->message->event)->toBe('e');
    expect($fromOld->message->retry)->toBe(1);
});
