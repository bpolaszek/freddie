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
