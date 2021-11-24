<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

interface PSR7TokenExtractorInterface
{
    public function extract(ServerRequestInterface $request): ?string;
}
