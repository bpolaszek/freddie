<?php

declare(strict_types=1);

namespace Freddie\Message;

use Freddie\Helper\TopicHelper;

use function Freddie\topic;

final class Update
{
    /**
     * @param string[] $topics
     */
    public function __construct(
        public array $topics,
        public Message $message,
    ) {
    }

    /**
     * @param string[] $allowedTopics
     */
    public function canBePublished(array $allowedTopics): bool
    {
        if ([] === $this->topics) {
            return false;
        }

        foreach ($this->topics as $topic) {
            if (!$this->checkTopicForPublication(topic($topic), $allowedTopics)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $subscribedTopics
     * @param string[]|null $allowedTopics
     */
    public function canBeReceived(array $subscribedTopics, ?array $allowedTopics, bool $allowAnonymous): bool
    {
        foreach ($this->topics as $topic) {
            if ($this->checkTopicForReception(topic($topic), $subscribedTopics, $allowedTopics, $allowAnonymous)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $subscribedTopics
     * @param string[]|null $allowedTopics
     */
    private function checkTopicForReception(
        TopicHelper $topic,
        array $subscribedTopics,
        ?array $allowedTopics,
        bool $allowAnonymous
    ): bool {
        if (!$topic->match($subscribedTopics)) {
            return false;
        }

        if (false === $allowAnonymous && null === $allowedTopics) {
            return false;
        }

        if (false === $this->message->private) {
            return true;
        }

        return $topic->match($allowedTopics ?? []);
    }

    /**
     * @param string[] $allowedTopics
     */
    private function checkTopicForPublication(TopicHelper $topic, array $allowedTopics): bool
    {
        if (true === $this->message->private) {
            return $topic->match($allowedTopics);
        }

        return true;
    }
}
