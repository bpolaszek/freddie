<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\QueryTokenExtractor;

use function Freddie\Tests\createSfRequest;

it('extracts token from authorization query parameter', function (string $uri, ?string $expected) {
    $extractor = new QueryTokenExtractor();
    $request = createSfRequest('GET', $uri);
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    yield ['/.well-known/mercure?authorization=' . $validToken, $validToken];
    yield ['/.well-known/mercure?authorization=foo', null];
    yield ['/.well-known/mercure?authorization', null];
    yield ['/.well-known/mercure', null];
});
