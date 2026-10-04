<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Matcher;

use BenTools\UrlPattern\URLPattern;
use Freddie\Matcher\MatcherType;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use InvalidArgumentException;
use ReflectionProperty;

it('matches topics', function (TopicMatcher $matcher, array $topics, bool $expected) {
    $store = new TopicMatcherStore();

    expect($store->matches($topics, $matcher))->toBe($expected);
})->with(function () {
    yield 'wildcard, exact' => [TopicMatcher::exact('*'), ['/foo'], true];
    yield 'wildcard, urlpattern' => [TopicMatcher::urlPattern('*'), ['foo'], true];
    yield 'exact' => [TopicMatcher::exact('/foo'), ['/bar', '/foo'], true];
    yield 'exact is byte-for-byte' => [TopicMatcher::exact('/foo'), ['/FOO', '/foo/'], false];
    yield 'exact does not expand templates' => [TopicMatcher::exact('/foo/{id}'), ['/foo/1'], false];
    yield 'urlpattern, absolute' => [
        TopicMatcher::urlPattern('https://example.com/books/:id'),
        ['https://example.com/books/42'],
        true,
    ];
    yield 'urlpattern, absolute, no match' => [
        TopicMatcher::urlPattern('https://example.com/books/:id'),
        ['https://example.com/authors/42'],
        false,
    ];
    yield 'urlpattern, relative' => [TopicMatcher::urlPattern('/books/:id'), ['/books/42'], true];
    yield 'urlpattern, relative pattern vs absolute topic' => [
        TopicMatcher::urlPattern('/books/:id'),
        ['https://example.com/books/42'],
        false,
    ];
    yield 'urlpattern, non-URL topic' => [TopicMatcher::urlPattern('https://example.com/*'), ['foo bar'], false];
    yield 'legacy, exact' => [TopicMatcher::legacy('/foo'), ['/foo'], true];
    yield 'legacy, uri template' => [TopicMatcher::legacy('/foo/{id}'), ['/foo/1'], true];
    yield 'legacy, uri template, no match' => [TopicMatcher::legacy('/foo/{id}'), ['/bar/1'], false];
    yield 'no topics' => [TopicMatcher::exact('/foo'), [], false];
});

it('resolves relative patterns against the configured base URL', function () {
    $store = new TopicMatcherStore('https://example.com/.well-known/mercure');

    expect($store->matches(['https://example.com/books/42'], TopicMatcher::urlPattern('/books/:id')))->toBeTrue()
        ->and($store->matches(['/books/42'], TopicMatcher::urlPattern('https://example.com/books/:id')))->toBeTrue()
        ->and($store->baseURL)->toBe('https://example.com/.well-known/mercure');
});

it('falls back to a synthetic base URL', function () {
    expect((new TopicMatcherStore())->baseURL)->toBe(TopicMatcherStore::FALLBACK_BASE_URL)
        ->and((new TopicMatcherStore(''))->baseURL)->toBe(TopicMatcherStore::FALLBACK_BASE_URL);
});

it('rejects a relative base URL', function () {
    new TopicMatcherStore('/.well-known/mercure');
})->throws(InvalidArgumentException::class, 'The base URL must be an absolute URL');

it('caches compiled patterns and match results within bounds', function () {
    $store = new TopicMatcherStore(cacheSize: 1);
    $cache = fn (string $name): array => (new ReflectionProperty($store, $name))->getValue($store);
    $books = TopicMatcher::urlPattern('/books/:id');

    // When
    $store->matches(['/books/1'], $books);

    // Then
    expect($cache('urlPatterns'))->toHaveKey('/books/:id')
        ->and($cache('matchResults'))->toBe(["/books/:id\0/books/1" => true]);

    // When: the compiled pattern is replaced by a stub, a cached result is served without it
    $stub = new URLPattern('/never', 'http://x');
    (new ReflectionProperty($store, 'urlPatterns'))->setValue($store, ['/books/:id' => $stub]);
    expect($store->matches(['/books/1'], $books))->toBeTrue()
        // but the stub is used for a topic which is not cached yet, which evicts the previous result
        ->and($store->matches(['/books/2'], $books))->toBeFalse()
        ->and($cache('matchResults'))->toBe(["/books/:id\0/books/2" => false]);

    // When: another pattern is compiled, the previous one is evicted
    $store->matches(['/authors/1'], TopicMatcher::urlPattern('/authors/:id'));
    expect(array_keys($cache('urlPatterns')))->toBe(['/authors/:id']);
});

it('works without cache', function () {
    $store = new TopicMatcherStore(cacheSize: 0);

    expect($store->matches(['/books/1'], TopicMatcher::urlPattern('/books/:id')))->toBeTrue()
        ->and((new ReflectionProperty($store, 'urlPatterns'))->getValue($store))->toBe([])
        ->and((new ReflectionProperty($store, 'matchResults'))->getValue($store))->toBe([]);
});

it('does not match when the pattern is invalid', function () {
    $store = new TopicMatcherStore();

    expect($store->matches(['/books/1'], TopicMatcher::urlPattern('/books/(')))->toBeFalse();
});

it('validates matchers', function (TopicMatcher $matcher, ?string $expectedError) {
    $store = new TopicMatcherStore();
    try {
        $store->validate($matcher);
        $error = null;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }

    expect($error)->toBe($expectedError);
})->with(function () {
    yield 'exact' => [TopicMatcher::exact('/foo'), null];
    yield 'urlpattern' => [TopicMatcher::urlPattern('/foo/:id'), null];
    yield 'urlpattern wildcard' => [TopicMatcher::urlPattern('*'), null];
    yield 'legacy' => [TopicMatcher::legacy('/foo/{id}'), null];
    yield 'invalid urlpattern' => [
        TopicMatcher::urlPattern('/foo/('),
        'Invalid topic matcher pattern (urlpattern).',
    ];
    yield 'control character' => [
        TopicMatcher::exact("/foo\n"),
        'Topic matcher values must be valid UTF-8 without control characters.',
    ];
    yield 'format character' => [
        TopicMatcher::exact("/foo\u{200B}"),
        'Topic matcher values must be valid UTF-8 without control characters.',
    ];
    yield 'invalid UTF-8' => [
        TopicMatcher::exact("/foo\xC3"),
        'Topic matcher values must be valid UTF-8 without control characters.',
    ];
    yield 'too long' => [
        TopicMatcher::exact(str_repeat('a', TopicMatcherStore::MAX_PATTERN_LENGTH + 1)),
        'Pattern too long (max 4096 bytes).',
    ];
});

it('detects topics in the reserved namespace', function (string $topic, bool $expected) {
    $store = new TopicMatcherStore('https://example.com/.well-known/mercure');

    expect($store->addressesReservedNamespace($topic))->toBe($expected);
})->with(function () {
    yield ['/.well-known/mercure', true];
    yield ['/.well-known/mercure/subscriptions/exact/foo/bar', true];
    yield ['/.well-known/mercure?foo', true];
    yield ['https://example.com/.well-known/mercure/subscriptions', true];
    yield ['https://other.example/.well-known/mercure', true];
    yield ['/%2Ewell-known/mercure', true];
    yield ['/foo/../.well-known/mercure', true];
    yield ['../.well-known/mercure/x', true];
    yield ['/.well-known/mercure-foo', false];
    yield ['/.well-known/mercurex/subscriptions', false];
    yield ['/foo', false];
    yield ['foo', false];
    yield ['urn:foo:/.well-known/mercure', false]; // opaque path
    yield ['https://example.com', false];
    yield ['#/.well-known/mercure', true]; // resolves to the hub URL itself
});

it('exposes wire matcher types only', function () {
    expect(MatcherType::fromWire('exact'))->toBe(MatcherType::Exact)
        ->and(MatcherType::fromWire('urlpattern'))->toBe(MatcherType::UrlPattern)
        ->and(MatcherType::fromWire('_legacy'))->toBeNull()
        ->and(MatcherType::fromWire('regexp'))->toBeNull();
});
