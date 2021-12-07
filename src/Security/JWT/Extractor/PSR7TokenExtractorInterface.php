<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

interface PSR7TokenExtractorInterface
{
    public function extract(ServerRequestInterface $request): ?string;
}
