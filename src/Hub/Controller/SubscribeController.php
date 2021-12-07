<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Freddie\Helper\FlatQueryParser;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Update;
use Freddie\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Freddie\extract_last_event_id;
use function BenTools\QueryString\query_string;
use function React\Async\async;

final class SubscribeController implements HubControllerInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private TransportInterface $transport,
        private PSR7TokenExtractorInterface $tokenExtractor,
        private JWTEncoderInterface $JWTEncoder,
        array $options,
    ) {
        $resolver = new OptionsResolver();
        $resolver->setRequired('allow_anonymous');
        $resolver->setAllowedTypes('allow_anonymous', 'bool');
        $this->options = $resolver->resolve($options);
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

        if (null !== $lastEventId) {
            async(
                function () use ($lastEventId, $stream, $subscribedTopics, $allowedTopics) {
                    foreach ($this->transport->reconciliate($lastEventId) as $update) {
                        $this->sendUpdate($update, $stream, $subscribedTopics, $allowedTopics);
                    }
                }
            );
        }

        async(
            function () use ($stream, $subscribedTopics, $allowedTopics) {
                $this->transport->subscribe(function (Update $update) use ($stream, $subscribedTopics, $allowedTopics) {
                    $this->sendUpdate($update, $stream, $subscribedTopics, $allowedTopics);
                });
            }
        );

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
        if (!$update->canBeReceived($subscribedTopics, $allowedTopics, $this->options['allow_anonymous'])) {
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
        $token = $this->tokenExtractor->extract($request);
        if (null === $token) {
            if (!$this->options['allow_anonymous']) {
                throw new AccessDeniedHttpException('Anonymous subscriptions are not allowed on this hub.');
            }

            return null;
        }

        try {
            $jwt = $this->JWTEncoder->decode($token);
        } catch (JWTDecodeFailureException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $jwt['mercure']['subscribe'] ?? null;
    }
}
