<?php

declare(strict_types=1);

namespace Freddie\Message;

use Symfony\Component\Uid\Ulid;

use function preg_split;

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

    /**
     * Fields are written as "name: value": SSE parsers strip one space after the colon, so a value
     * starting with a space would otherwise lose it.
     */
    public function __toString(): string
    {
        $output = 'id: ' . $this->id . PHP_EOL;

        if (null !== $this->event) {
            $output .= 'event: ' . $this->event . PHP_EOL;
        }

        if (null !== $this->retry) {
            $output .= 'retry: ' . $this->retry . PHP_EOL;
        }

        if (null !== $this->data) {
            // Each line of $data needs its own field: SSE parsers treat CRLF, LF and CR alike as line ends,
            // so a lone CR must not be able to inject another field.
            foreach (preg_split('/\r\n|\r|\n/', $this->data) ?: [] as $line) {
                $output .= 'data: ' . $line . PHP_EOL;
            }
        }

        return $output . PHP_EOL;
    }
}
