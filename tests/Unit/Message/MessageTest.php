<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Message;

use Freddie\Message\Message;
use Symfony\Component\Uid\Ulid;

it('has a default id', function () {
    $message = new Message();
    expect($message->id)->not()->toBeNull()
        ->and(Ulid::isValid((string) $message->id))->toBeTrue();
});
