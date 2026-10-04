<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Hub\Request\SubscriptionRequest;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Freddie\Subscription\Subscriber;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;

final class SubscribeController implements HubControllerInterface
{
    /**
     * SSE comment line: keeps proxies from closing idle connections and forces
     * the TCP stack to detect half-open peers so their subscribers get reaped.
     */
    private const HEARTBEAT = ":\n";

    /**
     * The SSE event type of the updates the hub generates itself (subscription events).
     */
    public const string RESERVED_EVENT_TYPE = 'mercure';

    private HubInterface $hub;

    public function setHub(HubInterface $hub): self
    {
        $this->hub = $hub;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getMethods(): array
    {
        return ['GET', SubscriptionRequest::QUERY_METHOD];
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
        $legacy = $this->hub->isLegacyProtocol();
        $store = $this->hub->getTopicMatcherStore();
        $subscriptionRequest = SubscriptionRequest::fromRequest($request, $store, $legacy);
        $grants = Grants::fromRequest($request, $store, $legacy);
        if (null === $grants && !$this->hub->getOption('allow_anonymous')) {
            throw BearerTokenException::missingToken('Anonymous subscriptions are not allowed on this hub.');
        }

        $subscriber = new Subscriber($subscriptionRequest->matchers, $store, $grants);

        // Live updates are buffered until the missed ones are sent, so that none is lost nor reordered
        // while the history is being read.
        $buffer = [];
        $live = false;
        $subscriber->setCallback(function (Update $update) use ($subscriber, $stream, &$buffer, &$live) {
            if (!$subscriber->canReceive($update)) {
                return;
            }

            if ($live) { // @phpstan-ignore if.alwaysFalse (set by reference once the missed updates are sent)
                $stream->write((string) $update->message);
            } else {
                $buffer[] = $update;
            }
        });
        $this->hub->subscribe($subscriber);
        $this->dispatchSubscriptionEvents($subscriber, true);
        $stream->on('close', function () use ($subscriber) {
            $this->hub->unsubscribe($subscriber);
            $this->dispatchSubscriptionEvents($subscriber, false);
        });

        $missed = [];
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
            'X-Accel-Buffering' => 'no',
            'Incremental' => '?1',
            'Accept-Query' => SubscriptionRequest::FORM_MEDIA_TYPE,
        ];
        if ($subscriptionRequest->hasLastEventId) {
            [$missed, $headers['Mercure-Last-Event-ID']] = $this->reconcile(
                $subscriber,
                $subscriptionRequest->lastEventId,
            );
            if ($legacy) {
                $headers['Last-Event-ID'] = $headers['Mercure-Last-Event-ID'];
            }
        }

        Loop::futureTick(function () use ($stream, $missed, &$buffer, &$live) {
            $sent = [];
            foreach ([...$missed, ...$buffer] as $update) {
                if (!isset($sent[$update->message->id])) {
                    $stream->write((string) $update->message);
                    $sent[$update->message->id] = true;
                }
            }
            $buffer = [];
            $live = true;
        });

        $heartbeatInterval = (float) $this->hub->getOption('heartbeat_interval');
        if ($heartbeatInterval > 0) {
            $timer = Loop::addPeriodicTimer(
                $heartbeatInterval,
                fn() => $stream->write(self::HEARTBEAT),
            );
            $stream->on('close', fn() => Loop::cancelTimer($timer));
        }

        return new Response(200, $headers, $stream);
    }

    /**
     * Returns the updates the subscriber missed and the ID of the event preceding the first one sent
     * (the reconciliation cursor): the requested ID, or "earliest" when it is empty, unknown or discarded.
     *
     * @return array{Update[], string}
     */
    private function reconcile(Subscriber $subscriber, ?string $lastEventId): array
    {
        if (null === $lastEventId) {
            return [[], TransportInterface::EARLIEST];
        }

        $missed = [];
        $history = $this->hub->reconciliate($lastEventId);
        foreach ($history as $update) {
            if ($subscriber->canReceive($update)) {
                $missed[] = $update;
            }
        }

        return true === $history->getReturn() ? [$missed, $lastEventId] : [[], TransportInterface::EARLIEST];
    }

    private function dispatchSubscriptionEvents(Subscriber $subscriber, bool $active): void
    {
        if (!$this->hub->getOption('subscriptions')) {
            return;
        }

        foreach ($subscriber->subscriptions as $subscription) {
            $this->hub->publish(new Update(
                [$subscription->getId()],
                new Message(data: $subscription->toJson($active), private: true, event: self::RESERVED_EVENT_TYPE),
            ));
        }
    }
}
