<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security;

use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Lcobucci\JWT\UnencryptedToken;

use function Freddie\Tests\grants;
use function Freddie\Tests\access_token;
use function Freddie\Tests\create_jwt;
use function Freddie\Tests\detail;
use function Freddie\Tests\jwt_config;

it('grants actions per authorization detail', function () {
    $grants = grants(access_token([
        detail(['publish'], ['/foo', ['match' => '/books/:id', 'match_type' => 'urlpattern']]),
        detail(['subscribe', 'unknown'], ['/bar']),
        ['type' => 'https://example.com/other-type', 'actions' => ['publish'], 'topics' => ['*']],
        'not an object',
    ]));

    expect($grants->canPublish(['/foo']))->toBeTrue()
        ->and($grants->canPublish(['/foo', '/books/42']))->toBeTrue()
        ->and($grants->canPublish(['/foo', '/bar']))->toBeFalse() // all topics must be granted
        ->and($grants->canPublish([]))->toBeFalse()
        ->and($grants->canSubscribe(['/bar']))->toBeTrue()
        ->and($grants->canSubscribe(['/baz', '/bar']))->toBeTrue() // any topic is enough
        ->and($grants->canSubscribe(['/foo']))->toBeFalse()
        ->and($grants->hasLegacyPublishClaim())->toBeFalse();
});

it('grants nothing without authorization details', function () {
    $grants = grants(create_jwt([]));

    expect($grants->canPublish(['/foo']))->toBeFalse()
        ->and($grants->canSubscribe(['/foo']))->toBeFalse();
});

it('grants everything with the wildcard', function () {
    $grants = grants(access_token([detail(['publish', 'subscribe'], ['*'])]));

    expect($grants->canPublish(['/foo', 'https://example.com/bar']))->toBeTrue()
        ->and($grants->canSubscribe(['/foo']))->toBeTrue();
});

it('ignores the legacy mercure claim outside compatibility mode', function () {
    $grants = grants(create_jwt(['mercure' => ['publish' => ['*'], 'subscribe' => ['*']]]));

    expect($grants->canPublish(['/foo']))->toBeFalse()
        ->and($grants->canSubscribe(['/foo']))->toBeFalse()
        ->and($grants->hasLegacyPublishClaim())->toBeFalse();
});

it('reads the legacy mercure claim in compatibility mode', function () {
    $grants = grants(
        create_jwt(['mercure' => ['publish' => ['/foo/{id}', 42], 'subscribe' => ['/bar'], 'payload' => ['x' => 1]]]),
        legacy: true,
    );

    expect($grants->canPublish(['/foo/1']))->toBeTrue()
        ->and($grants->canPublish(['/bar']))->toBeFalse()
        ->and($grants->canSubscribe(['/bar']))->toBeTrue()
        ->and($grants->hasLegacyPublishClaim())->toBeTrue()
        ->and($grants->payloadFor(TopicMatcher::exact('/bar')))->toBe(['x' => 1]);
});

it('reads both claims in compatibility mode', function () {
    $grants = grants(
        create_jwt([
            'mercure' => ['subscribe' => ['/bar']],
            'authorization_details' => [detail(['publish'], ['/foo'])],
        ]),
        legacy: true,
    );

    expect($grants->canPublish(['/foo']))->toBeTrue()
        ->and($grants->canSubscribe(['/bar']))->toBeTrue()
        ->and($grants->hasLegacyPublishClaim())->toBeFalse();
});

it('handles legacy tokens without the mercure claim', function () {
    $grants = grants(create_jwt(['mercure' => 'foo']), legacy: true);

    expect($grants->canPublish(['/foo']))->toBeFalse()
        ->and($grants->hasLegacyPublishClaim())->toBeFalse();
});

it('rejects an invalid legacy mercure claim', function () {
    grants(create_jwt(['mercure' => ['publish' => '*']]), legacy: true);
})->throws(BearerTokenException::class, 'Invalid mercure.publish claim.');

it('resolves the subscription payload', function () {
    $grants = grants(access_token([
        detail(['publish'], ['*'], ['ignored' => true]),
        detail(['subscribe'], ['/foo'], ['first' => true]),
        detail(['subscribe'], ['*']),
        detail(['subscribe'], [['match' => '/books/:id', 'match_type' => 'urlpattern']], ['books' => true]),
        detail(['subscribe'], ['*'], ['fallback' => true]),
    ]));

    expect($grants->payloadFor(TopicMatcher::exact('/foo')))->toBe(['first' => true])
        ->and($grants->payloadFor(TopicMatcher::exact('/books/42')))->toBe(['books' => true])
        ->and($grants->payloadFor(TopicMatcher::urlPattern('/books/:id')))->toBe(['books' => true])
        ->and($grants->payloadFor(TopicMatcher::urlPattern('/authors/:id')))->toBe(['fallback' => true]);
});

it('has no payload by default', function () {
    $grants = grants(access_token([detail(['subscribe'], ['*'])]));

    expect($grants->payloadFor(TopicMatcher::exact('/foo')))->toBeNull();
});

it('rejects malformed authorization details', function (mixed $details, string $expectedMessage) {
    try {
        grants(create_jwt(['authorization_details' => $details]));
        $this->fail('An exception should have been thrown.');
    } catch (BearerTokenException $e) {
        expect($e->getStatusCode())->toBe(401)
            ->and($e->error)->toBe(BearerTokenException::INVALID_TOKEN)
            ->and($e->getMessage())->toBe($expectedMessage);
    }
})->with(function () {
    $type = Grants::AUTHORIZATION_DETAIL_TYPE;

    yield 'not an array' => ['foo', 'The authorization_details claim must be an array.'];
    yield 'an object' => [['type' => $type], 'The authorization_details claim must be an array.'];
    yield 'no actions' => [
        [['type' => $type, 'topics' => [['match' => '*']]]],
        'Invalid authorization_details claim: a detail must declare at least one action.',
    ];
    yield 'empty actions' => [
        [['type' => $type, 'actions' => [], 'topics' => [['match' => '*']]]],
        'Invalid authorization_details claim: a detail must declare at least one action.',
    ];
    yield 'no topics' => [
        [['type' => $type, 'actions' => ['publish']]],
        'Invalid authorization_details claim: a detail must declare at least one topic.',
    ];
    yield 'topics is an object' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => ['match' => '*']]],
        'Invalid authorization_details claim: a detail must declare at least one topic.',
    ];
    yield 'bare string topic' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => ['*']]],
        'Invalid authorization_details claim: topic entries must be objects.',
    ];
    yield 'missing match' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match_type' => 'exact']]]],
        'Invalid authorization_details claim: a topic entry requires a non-empty "match" property.',
    ];
    yield 'empty match' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match' => '']]]],
        'Invalid authorization_details claim: a topic entry requires a non-empty "match" property.',
    ];
    yield 'unknown matcher type' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match' => '*', 'match_type' => 'regexp']]]],
        'Invalid authorization_details claim: unsupported topic matcher type.',
    ];
    yield 'matcher type is case-sensitive' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match' => '*', 'match_type' => 'Exact']]]],
        'Invalid authorization_details claim: unsupported topic matcher type.',
    ];
    yield 'internal matcher type' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match' => '*', 'match_type' => '_legacy']]]],
        'Invalid authorization_details claim: unsupported topic matcher type.',
    ];
    yield 'invalid pattern' => [
        [['type' => $type, 'actions' => ['publish'], 'topics' => [['match' => '/(', 'match_type' => 'urlpattern']]]],
        'Invalid authorization_details claim: Invalid topic matcher pattern (urlpattern)..',
    ];
    yield 'too many details' => [
        array_fill(0, Grants::MAX_DETAILS + 1, detail(['publish'], ['*'])),
        'Invalid authorization_details claim: too many authorization details (max 100).',
    ];
    yield 'too many topics' => [
        [detail(['publish'], array_fill(0, 60, '/foo')), detail(['publish'], array_fill(0, 41, '/foo'))],
        'Invalid authorization_details claim: too many topics (max 100).',
    ];
});
