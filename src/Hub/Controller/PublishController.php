<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Hub\Controller;

use BenTools\MercurePHP\Helper\FlatQueryParser;
use BenTools\MercurePHP\Hub\HubControllerInterface;
use BenTools\MercurePHP\Hub\Transport\TransportInterface;
use BenTools\MercurePHP\Message\Message;
use BenTools\MercurePHP\Message\Update;
use BenTools\MercurePHP\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;

use function BenTools\MercurePHP\is_truthy;
use function BenTools\MercurePHP\nullify;
use function BenTools\QueryString\query_string;

final class PublishController implements HubControllerInterface
{

    public function __construct(
        private TransportInterface $transport,
        private PSR7TokenExtractorInterface $tokenExtractor,
        private JWTEncoderInterface $JWTEncoder,
    ) {
    }

    public function getMethod(): string
    {
        return 'post';
    }

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

        $this->transport->publish($update);

        return new Response(201, body: (string) $update->message->id);
    }

    /**
     * @param ServerRequestInterface $request
     * @return string[]
     */
    private function extractAllowedTopics(ServerRequestInterface $request): array
    {
        $token = $this->tokenExtractor->extract($request);
        if (null === $token) {
            throw new AccessDeniedHttpException('You must be authenticated to publish on this hub.');
        }

        try {
            $jwt = $this->JWTEncoder->decode($token);
        } catch (JWTDecodeFailureException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $jwt['mercure']['publish']
            ?? throw new AccessDeniedHttpException('Missing mercure.publish claim.');
    }
}
