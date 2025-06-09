<?php

declare(strict_types=1);

namespace Freddie\Tests\Mock;

use Freddie\Message\Message;
use Freddie\Message\Update;

use function assert;
use function expect;
use function iterator_to_array;

it('only returns new updates when no lastEventID is provided', function () {
    $updates = [
        new Update('example', new Message(data: 'foo')),
        new Update('example', new Message(data: 'bar')),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $transport = new PHPTransport($updates);

    $result = iterator_to_array($transport->listen());
    expect($result)->toBe([]);

    $update = new Update('example', new Message(data: 'baz'));
    $transport->push($update);
    $result = iterator_to_array($transport->listen());
    expect($result)->toBe([$update]);
});

it('only returns updates greater than lastEventID', function () {
    $updates = [
        new Update('example', new Message(data: 'foo')),
        new Update('example', new Message(data: 'bar')),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $transport = new PHPTransport($updates);

    $result = iterator_to_array($transport->listen((string) $updates[0]->message->id));
    expect($result)->toBe([$updates[1]]);

    $update = new Update('example', new Message(data: 'baz'));
    $transport->push($update);
    $result = iterator_to_array($transport->listen((string) $updates[0]->message->id));
    expect($result)->toBe([$updates[1], $update]);
});

it('returns all updates when no lastEventID is provided', function () {
    $updates = [
        new Update('example', new Message(data: 'foo')),
        new Update('example', new Message(data: 'bar')),
    ];

    assert(-1 === ($updates[0]->message->id <=> $updates[1]->message->id));
    $transport = new PHPTransport($updates);

    $result = iterator_to_array($transport->listen('earliest'));
    expect($result)->toBe($updates);

    $update = new Update('example', new Message(data: 'baz'));
    $transport->push($update);
    $result = iterator_to_array($transport->listen('earliest'));
    expect($result)->toBe([...$updates, $update]);
});
