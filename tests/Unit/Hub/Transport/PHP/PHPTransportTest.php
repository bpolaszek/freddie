<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Hub\Transport\PHP;

use BenTools\MercurePHP\Hub\Transport\PHP\PHPTransport;
use BenTools\MercurePHP\Message\Message;
use BenTools\MercurePHP\Message\Update;

use function array_slice;
use function iterator_to_array;

it('dispatches published updates', function () {
    $transport = new PHPTransport();
    $update = new Update(['/foo'], new Message());
    $subscriber = (object) ['received' => null];
    $transport->subscribe(fn ($receivedUpdate) => $subscriber->received = $receivedUpdate);
    $transport->publish($update);
    expect($subscriber->received ?? null)->toBe($update);
});

it('performs state reconciliation', function () {
    $transport = new PHPTransport(size: 3);
    $updates = [
        new Update(['/foo'], new Message(id: '1')),
        new Update(['/foo'], new Message(id: '2')),
        new Update(['/foo'], new Message(id: '3')),
        new Update(['/foo'], new Message(id: '4')),
        new Update(['/foo'], new Message(id: '5')),
        new Update(['/foo'], new Message(id: '6')),
        new Update(['/foo'], new Message(id: '7')),
        new Update(['/foo'], new Message(id: '8')),
        new Update(['/foo'], new Message(id: '9')),
        new Update(['/foo'], new Message(id: '10')),
    ];
    foreach ($updates as $update) {
        $transport->publish($update);
    }

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate($transport::EARLIEST));

    // Then
    expect($missedUpdates)->toBe(array_slice($updates, 7, 3));

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate('1'));

    // Then
    expect($missedUpdates)->toBe([]);

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate('9'));

    // Then
    expect($missedUpdates)->toBe([$updates[9]]);
});
