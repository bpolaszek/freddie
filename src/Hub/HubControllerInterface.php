<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface HubControllerInterface
{
    /**
     * @return string[]
     */
    public function getMethods(): array;
    public function getRoute(): string;
    public function setHub(HubInterface $hub): self;
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
