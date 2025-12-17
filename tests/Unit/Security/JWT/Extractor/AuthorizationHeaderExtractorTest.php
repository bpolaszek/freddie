<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\AuthorizationHeaderTokenExtractor;

use function Freddie\Tests\createSfRequest;

it('extracts token from authorization header', function (array $headers, ?string $expected) {
    $extractor = new AuthorizationHeaderTokenExtractor();
    $request = createSfRequest('GET', '/.well-known/mercure', $headers);
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    yield [['Authorization' => 'Bearer ' . $validToken], $validToken];
    yield [['Authorization' => 'foobar'], null];
    yield [['Authorization' => ''], null];
    yield [[], null];
});
