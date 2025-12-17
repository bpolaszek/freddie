<?php

declare(strict_types=1);

namespace Freddie\Hub\Middleware;

use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validator;
use Lcobucci\JWT\Validation\Validator as DefaultValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class TokenExtractorMiddleware
{
    public function __construct(
        private Parser $parser = new Parser(new JoseEncoder()),
        private Validator $validator = new DefaultValidator(),
        private ValidationConstraints $validationConstraints = new ValidationConstraints([]),
        private PSR7TokenExtractorInterface $tokenExtractor = new ChainTokenExtractor(),
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $this->tokenExtractor->extract($request);

        return $next($this->withToken($request, $token));
    }

    private function withToken(ServerRequestInterface $request, ?string $token): ServerRequestInterface
    {
        if (empty($token)) {
            return $request;
        }

        try {
            $jwt = $this->parser->parse($token);
            $this->validator->assert($jwt, ...$this->validationConstraints->constraints);
        } catch (Exception $e) {
            throw new AccessDeniedHttpException($e->getMessage());
        }

        return $request->withAttribute('token', $jwt);
    }
}
