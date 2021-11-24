<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Helper;

use BenTools\MercurePHP\Helper\FlatQueryParser;

use function BenTools\QueryString\query_string;

it('parses flat query strings', function () {
    $qs = query_string('topic=foo&topic=bar&topic=foo%20bar&foo=bar&hi', new FlatQueryParser());
    expect($qs->getParams())->toBe([
        'topic' => ['foo', 'bar', 'foo bar'],
        'foo' => 'bar',
        'hi' => null,
    ]);
});
