<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Freddie\Helper\FlatQueryParser;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Ulid;
use Throwable;

use function BenTools\QueryString\query_string;
use function Freddie\is_truthy;
use function Freddie\nullify;
use function React\Async\await;

final class PublishController implements HubControllerInterface
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
        return 'post';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getRoute(): string
    {
        return '/.well-known/mercure';
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = query_string((string) $request->getBody(), new FlatQueryParser());
        if (!$input->hasParam('topic')) {
            throw new BadRequestHttpException('Missing topic parameter.');
        }

        $message = new Message(
            id: nullify($input->getParam('id'), 'string') ?? Ulid::generate(),
            data: nullify($input->getParam('data'), 'string'),
            private: is_truthy($input->getParam('private')),
            event: nullify($input->getParam('event'), 'string'),
            retry: nullify($input->getParam('retry'), 'int'),
        );
        $update = new Update((array) $input->getParam('topic'), $message);

        $allowedTopics = $this->extractAllowedTopics($request);
        if (!$update->canBePublished($allowedTopics)) {
            throw new AccessDeniedHttpException('Your rights are not sufficient to publish this update.');
        }

        try {
            await($this->hub->publish($update));
        } catch (Throwable) {
            throw new ServiceUnavailableHttpException();
        }

        return new Response(201, body: (string) $update->message->id);
    }

    /**
     * @param ServerRequestInterface $request
     * @return string[]
     */
    private function extractAllowedTopics(ServerRequestInterface $request): array
    {
        /** @var UnencryptedToken $jwt */
        $jwt = $request->getAttribute('token')
            ?? throw new AccessDeniedHttpException('You must be authenticated to publish on this hub.');

        return $jwt->claims()->get('mercure')['publish']
            ?? throw new AccessDeniedHttpException('Missing mercure.publish claim.');
    }
}
