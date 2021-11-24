<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Message;

use BenTools\MercurePHP\Message\Message;

it('stringifies messages', function (Message $message, string $expected) {
    expect((string) $message)->toBe($expected);
})->with(function () {
    yield [
        new Message(id: '1'),
        "id:1\n\n",
    ];
    yield [
        new Message(id: '1', event: 'message'),
        "id:1\nevent:message\n\n",
    ];
    yield [
        new Message(id: '1', private: true, event: 'message'),
        "id:1\nevent:message\n\n",
    ];
    yield [
        new Message(id: '1', event: 'message', retry: 3),
        "id:1\nevent:message\nretry:3\n\n",
    ];
    yield [
        new Message(id: '1', data: 'bar', event: 'message'),
        "id:1\nevent:message\ndata:bar\n\n",
    ];
    yield [
        new Message(id: '1', data: "foo\nbar", event: 'message'),
        "id:1\nevent:message\ndata:foo\ndata:bar\n\n",
    ];
});
