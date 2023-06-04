<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface HubControllerInterface
{
    public function getMethod(): string;
    public function getRoute(): string;
    public function setHub(HubInterface $hub): self;

    public function setLogger(LoggerInterface $logger): self;
    public function __invoke(ServerRequestInterface $request): ResponseInterface;
}
