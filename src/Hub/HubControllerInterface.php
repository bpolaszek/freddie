<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Hub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface HubControllerInterface
{
    public function getMethod(): string;
    public function getRoute(): string;
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
