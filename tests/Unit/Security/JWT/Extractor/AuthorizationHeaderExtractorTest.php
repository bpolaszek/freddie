<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\AuthorizationHeaderTokenExtractor;
use React\Http\Message\ServerRequest;

it('extracts token from authorization header', function (array $headers, ?string $expected) {
    $extractor = new AuthorizationHeaderTokenExtractor();
    $request = new ServerRequest('GET', '/.well-known/mercure', $headers);
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    yield [['Authorization' => 'Bearer foobar'], 'foobar'];
    yield [['Authorization' => 'foobar'], null];
    yield [['Authorization' => ''], null];
    yield [[], null];
});
