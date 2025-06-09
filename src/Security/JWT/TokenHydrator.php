<?php

namespace Freddie\Security\JWT;

use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\TokenExtractorInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Validator;
use Symfony\Component\HttpFoundation\Request;

final readonly class TokenHydrator
{
    public const string ATTRIBUTE_NAME = 'freddie_auth_token';

    public function __construct(
        private TokenExtractorInterface $tokenExtractor = new ChainTokenExtractor(),
        private Parser $parser = new Token\Parser(new JoseEncoder()),
        private Validator $validator = new Validator(),
        private ValidationConstraints $validationConstraints = new ValidationConstraints(),
    ) {
    }

    public function getToken(Request $request): ?Token
    {
        return $request->attributes->get(self::ATTRIBUTE_NAME, (function () use ($request) {
            $token = $this->tokenExtractor->extract($request);
            if (null !== $token) {
                $jwt = $this->parser->parse($token);
                $this->validator->assert($jwt, ...$this->validationConstraints);
                $request->attributes->set(self::ATTRIBUTE_NAME, $jwt);
            }

            return $jwt ?? null;
        })());
    }
}
