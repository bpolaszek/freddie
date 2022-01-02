<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\CookieTokenExtractor;
use React\Http\Message\ServerRequest;

it('extracts token from cookies', function () {
    $extractor = new CookieTokenExtractor();
    $request = new ServerRequest('GET', '/.well-known/mercure', [
        'Cookie' => 'foo=bar; mercureAuthorization=foobar; bar=foo',
    ]);
    expect($extractor->extract($request))->toBe('foobar');
});

it('is compliant with the split-cookie strategy', function () {
    $extractor = new CookieTokenExtractor(['jwt_hp', 'jwt_s']);
    $request = new ServerRequest('GET', '/.well-known/mercure', [
        'Cookie' => 'foo=bar; jwt_hp=foobar; bar=foo; jwt_s=signed',
    ]);
    expect($extractor->extract($request))->toBe('foobar.signed');
});
