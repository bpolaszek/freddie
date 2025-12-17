<?php

declare(strict_types=1);

namespace Freddie\Message;

use Symfony\Component\Uid\Ulid;

final readonly class Message
{
    public Ulid $id;

    public function __construct(
        ?Ulid $id = null,
        public ?string $data = null,
        public bool $private = false,
        public ?string $event = null,
        public ?int $retry = null,
    ) {
        $this->id = $id ?? new Ulid();
    }
}
