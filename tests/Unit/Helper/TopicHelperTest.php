<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Helper;

use function Freddie\topic;

it('matches topics', function (string $topic, array $matches, bool $expected) {
    expect(topic($topic)->match($matches))->toBe($expected);
})
->with(function () {
    yield [
        'topic' => '/foo',
        'matches' => [],
        'expected' => false,
    ];
    yield [
        'topic' => '/foo',
        'matches' => ['/bar'],
        'expected' => false,
    ];
    yield [
        'topic' => '/foo',
        'matches' => ['/bar'],
        'expected' => false,
    ];
    yield [
        'topic' => '/foo',
        'matches' => ['/foo'],
        'expected' => true,
    ];
    yield [
        'topic' => '/foo',
        'matches' => ['/foobar'],
        'expected' => false,
    ];
    yield [
        'topic' => '/foobar',
        'matches' => ['/foo'],
        'expected' => false,
    ];
    yield [
        'topic' => '/foo/bar',
        'matches' => ['/foo/{bar}'],
        'expected' => true,
    ];
});

it('can be subscribed', function (string $topic, array $allowedTopics, bool $allowAnonymous, bool $expected) {
    expect(topic($topic)->canBeSubscribed($allowedTopics, $allowAnonymous))->toBe($expected);
})
->with(function () {
    yield [
        'topic' => '/foo',
        'allowedTopics' => [],
        'allowAnonymous' => true,
        'expected' => true,
    ];
    yield [
        'topic' => '/foo',
        'allowedTopics' => [],
        'allowAnonymous' => false,
        'expected' => false,
    ];
    yield [
        'topic' => '/foo',
        'allowedTopics' => ['/foo'],
        'allowAnonymous' => false,
        'expected' => true,
    ];
    yield [
        'topic' => '/foo',
        'allowedTopics' => ['*'],
        'allowAnonymous' => false,
        'expected' => true,
    ];
    yield [
        'topic' => '/foo/{bar}',
        'allowedTopics' => ['/foo/{bar}'],
        'allowAnonymous' => false,
        'expected' => true,
    ];
    yield [
        'topic' => '/foo/bar',
        'allowedTopics' => ['/foo/{bar}'],
        'allowAnonymous' => false,
        'expected' => true,
    ];
});

it('returns clones', function () {
    expect(topic('/foo'))->not()->toBe(topic('/foo'));
});
