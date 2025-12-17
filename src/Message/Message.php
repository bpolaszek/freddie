<?php

declare(strict_types=1);

namespace Freddie\Message;

use Symfony\Component\Uid\Ulid;

use function explode;
use function str_contains;

use const PHP_EOL;

final readonly class Message
{
    public string $id;

    public function __construct(
        ?string $id = null,
        public ?string $data = null,
        public bool $private = false,
        public ?string $event = null,
        public ?int $retry = null,
    ) {
        $this->id = $id ?? (string) new Ulid();
    }

    public function __toString(): string
    {
        $output = 'id:' . $this->id . PHP_EOL;

        if (null !== $this->event) {
            $output .= 'event:' . $this->event . PHP_EOL;
        }

        if (null !== $this->retry) {
            $output .= 'retry:' . $this->retry . PHP_EOL;
        }

        if (null !== $this->data) {
            // If $data contains line breaks, we have to serialize it in a different way
            if (str_contains($this->data, PHP_EOL)) {
                $lines = explode(PHP_EOL, $this->data);
                foreach ($lines as $line) {
                    $output .= 'data:' . $line . PHP_EOL;
                }
            } else {
                $output .= 'data:' . $this->data . PHP_EOL;
            }
        }

        return $output . PHP_EOL;
    }
}
