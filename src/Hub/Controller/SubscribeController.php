<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Freddie\Helper\FlatQueryParser;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function Freddie\extract_last_event_id;
use function BenTools\QueryString\query_string;
use function React\Async\async;

final class SubscribeController implements HubControllerInterface
{
    private HubInterface $hub;

    public function setHub(HubInterface $hub): self
    {
        $this->hub = $hub;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getMethod(): string
    {
        return 'get';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getRoute(): string
    {
        return '/.well-known/mercure';
    }

    public function __invoke(
        ServerRequestInterface $request,
        WritableStreamInterface&ReadableStreamInterface $stream = new ThroughStream(),
    ): ResponseInterface {
        $subscribedTopics = $this->extractSubscribedTopics($request);
        $allowedTopics = $this->extractAllowedTopics($request);
        $lastEventId = extract_last_event_id($request);

        $subscriber = new Subscriber($subscribedTopics);

        if (null !== $lastEventId) {
            async(
                function () use ($lastEventId, $stream, $subscribedTopics, $allowedTopics) {
                    foreach ($this->hub->reconciliate($lastEventId) as $update) {
                        $this->sendUpdate($update, $stream, $subscribedTopics, $allowedTopics);
                    }
                }
            )();
        }

        async(
            function () use ($stream, $subscribedTopics, $allowedTopics, $subscriber) {
                $callback = fn(Update $update) => $this->sendUpdate(
                    $update,
                    $stream,
                    $subscribedTopics,
                    $allowedTopics
                );
                $subscriber->setCallback($callback);
                $this->hub->subscribe($subscriber);
                $stream->on('close', fn() => $this->hub->unsubscribe($subscriber));
            }
        )();

        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $stream
        );
    }

    /**
     * @param string[] $subscribedTopics
     * @param string[]|null $allowedTopics
     */
    private function sendUpdate(
        Update $update,
        WritableStreamInterface $stream,
        array $subscribedTopics,
        ?array $allowedTopics,
    ): void {
        if (!$update->canBeReceived($subscribedTopics, $allowedTopics, $this->hub->getOption('allow_anonymous'))) {
            return;
        }

        $stream->write((string) $update->message);
    }

    /**
     * @return string[]
     */
    private function extractSubscribedTopics(ServerRequestInterface $request): array
    {
        $qs = query_string($request->getUri(), new FlatQueryParser());
        if (!$qs->hasParam('topic')) {
            throw new BadRequestHttpException('Missing topic parameter.');
        }

        return (array) $qs->getParam('topic');
    }

    /**
     * @return string[]|null
     */
    private function extractAllowedTopics(ServerRequestInterface $request): ?array
    {
        /** @var UnencryptedToken|null $jwt */
        $jwt = $request->getAttribute('token');
        if (null === $jwt) {
            if (!$this->hub->getOption('allow_anonymous')) {
                throw new AccessDeniedHttpException('Anonymous subscriptions are not allowed on this hub.');
            }

            return null;
        }

        return $jwt->claims()->get('mercure')['subscribe'] ?? null;
    }
}
