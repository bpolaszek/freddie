<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Message;

use Freddie\Message\Message;
use Freddie\Message\Update;

it('accepts a single topic', function () {
    $update = new Update('/foo', new Message());
    expect($update->topics)->toBe(['/foo']);
});
