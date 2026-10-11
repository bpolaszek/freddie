<?php

declare(strict_types=1);

namespace Freddie\Message;

use function is_string;

final readonly class Update
{
    /**
     * @var string[]
     */
    public array $topics;

    /**
     * @param string[] $topics
     */
    public function __construct(
        array|string $topics,
        public Message $message,
    ) {
        $this->topics = is_string($topics) ? [$topics] : $topics;
    }
}
