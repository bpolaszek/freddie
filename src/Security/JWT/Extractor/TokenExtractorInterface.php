<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Symfony\Component\HttpFoundation\Request;

interface TokenExtractorInterface
{
    public function extract(Request $request): ?string;
}
