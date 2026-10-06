<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use DateTimeImmutable;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Hub\Request\SubscriptionRequest;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Freddie\Subscription\Subscriber;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;

use function json_encode;
use function max;
use function microtime;

use const JSON_THROW_ON_ERROR;

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

        // Authenticated connections must not outlive their access token: they are closed when it expires,
        // and nothing is sent once it has expired.
        $expiresAt = self::getExpiration($request);
        $send = function (Update $update) use ($stream, $expiresAt): void {
            if (null !== $expiresAt && $expiresAt <= new DateTimeImmutable()) {
                $stream->close();

                return;
            }

            $stream->write((string) $update->message);
        };
        if (null !== $expiresAt) {
            $expiration = Loop::addTimer(
                max(0.0, (float) $expiresAt->format('U.u') - microtime(true)),
                fn () => $stream->close(),
            );
            $stream->on('close', fn () => Loop::cancelTimer($expiration));
        }

        // Live updates are buffered until the missed ones are sent, so that none is lost nor reordered
        // while the history is being read.
        $buffer = [];
        $live = false;
        $subscriber->setCallback(function (Update $update) use ($subscriber, $send, &$buffer, &$live) {
            if (!$subscriber->canReceive($update)) {
                return;
            }

            if ($live) { // @phpstan-ignore if.alwaysFalse (set by reference once the missed updates are sent)
                $send($update);
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

        Loop::futureTick(function () use ($send, $missed, &$buffer, &$live) {
            // A live update may also have been read from the history: it is sent once. Publishers can reuse IDs,
            // so occurrences of the whole update are counted rather than deduplicated by ID.
            $pending = [];
            foreach ($missed as $update) {
                $send($update);
                $key = self::occurrenceKey($update);
                $pending[$key] = ($pending[$key] ?? 0) + 1;
            }
            foreach ($buffer as $update) {
                $key = self::occurrenceKey($update);
                if (($pending[$key] ?? 0) > 0) {
                    $pending[$key]--;
                    continue;
                }
                $send($update);
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

    /**
     * Identifies an update by its whole content: the transports provide no occurrence identifier, and the
     * history and the live stream may hold distinct instances of the same update (e.g. with Redis).
     */
    private static function occurrenceKey(Update $update): string
    {
        return json_encode([$update->topics, $update->message->private], JSON_THROW_ON_ERROR) . $update->message;
    }

    private static function getExpiration(ServerRequestInterface $request): ?DateTimeImmutable
    {
        $token = $request->getAttribute('token');
        if (!$token instanceof UnencryptedToken) {
            return null;
        }

        $expiresAt = $token->claims()->get(RegisteredClaims::EXPIRATION_TIME);

        return $expiresAt instanceof DateTimeImmutable ? $expiresAt : null;
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
            ))->catch(static fn () => null); // Best effort: a transport failure must not break the connection.
        }
    }
}
