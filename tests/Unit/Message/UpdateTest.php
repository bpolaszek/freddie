<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Message;

use BenTools\MercurePHP\Message\Message;
use BenTools\MercurePHP\Message\Update;

it('can be published', function (Update $update, array $allowedTopics, bool $expected) {
    expect($update->canBePublished($allowedTopics))->toBe($expected);
})->with(function () {
    yield [
        'update' => new Update(['/foo'], new Message()),
        'allowedTopics' => ['/foo'],
        'expected' => true,
    ];
    yield [
        'update' => new Update(['/bar'], new Message()),
        'allowedTopics' => ['/foo'],
        'expected' => true, // Because this is a public update
    ];
    yield [
        'update' => new Update(['/bar'], new Message(private: true)),
        'allowedTopics' => ['/foo'],
        'expected' => false,
    ];
    yield [
        'update' => new Update(['/foo'], new Message(private: true)),
        'allowedTopics' => ['/foo'],
        'expected' => true,
    ];
    yield [
        'update' => new Update([], new Message()),
        'allowedTopics' => ['/foo'],
        'expected' => false,
    ];
});

it('can be received', function (
    Update $update,
    array $subscribedTopics,
    ?array $allowedTopics,
    bool $allowAnonymous,
    bool $expected
) {
    expect($update->canBeReceived($subscribedTopics, $allowedTopics, $allowAnonymous))->toBe($expected);
})->with(function () {
    yield [
        'update' => new Update(['/foo'], new Message()),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => ['/foo'],
        'allowAnonymous' => true,
        'expected' => true,
    ];
    yield [
        'update' => new Update(['/foo'], new Message()),
        'subscribedTopics' => ['/bar'],
        'allowedTopics' => ['/foo'],
        'allowAnonymous' => true,
        'expected' => false, // Allowed, but not subscribed
    ];
    yield [
        'update' => new Update(['/foo'], new Message()),
        'subscribedTopics' => ['*'],
        'allowedTopics' => ['/foo'],
        'allowAnonymous' => true,
        'expected' => true, // Subscribed to all
    ];
    yield [
        'update' => new Update(['/foo'], new Message()),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => null,
        'allowAnonymous' => true,
        'expected' => true, // Because public update
    ];
    yield [
        'update' => new Update(['/foo'], new Message()),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => null,
        'allowAnonymous' => false,
        'expected' => false, // Because public update, but anonymous forbidden
    ];
    yield [
        'update' => new Update(['/foo'], new Message(private: true)),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => null,
        'allowAnonymous' => true,
        'expected' => false, // Because private update
    ];
    yield [
        'update' => new Update(['/foo'], new Message(private: true)),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => ['/foo'],
        'allowAnonymous' => true,
        'expected' => true, // Private update allowed on /foo
    ];
    yield [
        'update' => new Update(['/foo'], new Message(private: true)),
        'subscribedTopics' => ['/foo'],
        'allowedTopics' => ['*'],
        'allowAnonymous' => true,
        'expected' => true, // Private update allowed on all
    ];
});
