<?php

declare(strict_types=1);

namespace Freddie\Tests;

use Clue\React\EventSource\EventSource;
use Clue\React\EventSource\MessageEvent;
use DateTimeImmutable;
use FrameworkX\App;
use Freddie\Hub\Controller\PublishController;
use Freddie\Hub\Controller\SubscribeController;
use Freddie\Hub\Controller\SubscriptionsController;
use Freddie\Hub\Hub;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Update;
use Freddie\Security\Grants;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Validation\AccessTokenPolicy;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use ReflectionClass;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

function run_loop(float $seconds = 0.01): void
{
    Loop::addTimer($seconds, fn () => Loop::stop());
    Loop::run();
}

function handle(App $app, ServerRequestInterface $request): ResponseInterface
{
    static $class, $method;
    $class ??= new ReflectionClass($app);
    $method ??= $class->getMethod('handleRequest');
    $method->setAccessible(true);

    return $method->invoke($app, $request);
}

const ISSUER = 'https://localhost';
const RESOURCE_IDENTIFIER = 'https://localhost/.well-known/mercure';

function access_token_policy(bool $legacy = false): AccessTokenPolicy
{
    return new AccessTokenPolicy($legacy, ISSUER, RESOURCE_IDENTIFIER);
}

/**
 * Parses and validates the token like the hub would, then stores it as a request attribute.
 * Signature is not verified.
 */
function with_token(ServerRequestInterface $request, string $token, bool $legacy = false): ServerRequestInterface
{
    $hydrater = new TokenExtractorMiddleware(accessTokenPolicy: access_token_policy($legacy));
    $method = (new ReflectionClass($hydrater))->getMethod('withToken');

    return $method->invoke($hydrater, $request, $token);
}

function jwt_config(): Configuration
{
    static $factory, $config;
    $factory ??= new ConfigurationFactory();

    return $config ??= $factory(
        $_SERVER['JWT_ALGORITHM'],
        \file_get_contents(\strtr($_SERVER['JWT_SECRET_KEY'], ['%kernel.project_dir%' => \dirname(__DIR__)])),
        \file_get_contents(\strtr($_SERVER['JWT_PUBLIC_KEY'], ['%kernel.project_dir%' => \dirname(__DIR__)])),
        $_SERVER['JWT_PASSPHRASE'],
    );
}

/**
 * The Symfony-based serializer previously used for the Redis transport,
 * kept as a dev dependency to assert wire-format compatibility.
 */
function legacy_redis_serializer(): Serializer
{
    static $serializer;

    return $serializer ??= new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
}

/**
 * Creates a token with arbitrary claims (e.g. a protocol version 8 token with a `mercure` claim).
 */
function create_jwt(array $claims, array $headers = []): string
{
    $builder = jwt_config()->builder();
    foreach ($headers as $key => $value) {
        $builder = $builder->withHeader($key, $value);
    }
    foreach ($claims as $key => $value) {
        $builder = match ($key) {
            'iss' => $builder->issuedBy($value),
            'aud' => $builder->permittedFor(...(array) $value),
            'exp' => $builder->expiresAt($value),
            default => $builder->withClaim($key, $value),
        };
    }

    return $builder->getToken(jwt_config()->signer(), jwt_config()->signingKey())->toString();
}

/**
 * Creates an RFC 9068 access token carrying the given Mercure authorization details.
 *
 * @param array<array<string, mixed>> $details see detail()
 */
function access_token(array $details = [], array $claims = []): string
{
    return create_jwt(
        [
            'iss' => ISSUER,
            'aud' => RESOURCE_IDENTIFIER,
            'exp' => new DateTimeImmutable('+1 hour'),
            'authorization_details' => $details,
            ...$claims,
        ],
        ['typ' => 'at+jwt'],
    );
}

/**
 * A Mercure authorization detail. Topics given as strings are exact matchers.
 *
 * @param string[] $actions
 * @param array<string|array<string, string>> $topics
 */
function detail(array $actions, array $topics, mixed $payload = null): array
{
    $detail = [
        'type' => Grants::AUTHORIZATION_DETAIL_TYPE,
        'actions' => $actions,
        'topics' => array_map(fn ($topic) => \is_string($topic) ? ['match' => $topic] : $topic, $topics),
    ];
    if (null !== $payload) {
        $detail['payload'] = $payload;
    }

    return $detail;
}

function grants(string $token, bool $legacy = false): Grants
{
    /** @var UnencryptedToken $jwt */
    $jwt = jwt_config()->parser()->parse($token);

    return Grants::fromToken($jwt, new TopicMatcherStore(), $legacy);
}

/**
 * An app storing the token the middleware extracted (or "No token provided") into $token.
 */
function token_app(AccessTokenPolicy $policy, mixed &$token): App
{
    return new App(
        new HttpExceptionConverterMiddleware(),
        new TokenExtractorMiddleware(
            jwt_config()->parser(),
            jwt_config()->validator(),
            new ValidationConstraints([new LooseValidAt(Clock::get())]),
            accessTokenPolicy: $policy,
        ),
        function (ServerRequestInterface $request) use (&$token) {
            $token = $request->getAttribute('token') ?? 'No token provided';

            return new Response(204);
        }
    );
}

/**
 * @return array{App, TransportInterface}
 */
function publish_app(array $options = [], ?TransportInterface $transport = null): array
{
    $transport ??= new PHPTransport(size: 1);
    $controller = new PublishController();
    $app = new App(
        new HttpExceptionConverterMiddleware(),
        new TokenExtractorMiddleware(
            jwt_config()->parser(),
            jwt_config()->validator(),
            accessTokenPolicy: access_token_policy(isset($options['protocol_compatibility'])),
        ),
        $controller,
    );
    $controller->setHub(new Hub($app, $transport, $options));

    return [$app, $transport];
}

function published_update(PHPTransport $transport): ?Update
{
    return (new ReflectionClass($transport))->getProperty('updates')->getValue($transport)[0] ?? null;
}

function publish_request(string $jwt, string $body): ServerRequest
{
    return new ServerRequest(
        'POST',
        '/.well-known/mercure',
        ['Authorization' => "Bearer $jwt", 'Content-Type' => 'application/x-www-form-urlencoded'],
        body: $body,
    );
}

function subscribe_controller(?PHPTransport $transport = null, array $options = []): SubscribeController
{
    $controller = new SubscribeController();
    $controller->setHub(new Hub(
        transport: $transport ?? new PHPTransport(size: 1000),
        options: ['heartbeat_interval' => 0, ...$options],
    ));

    return $controller;
}

/**
 * @return array{App, Hub, SubscribeController}
 */
function subscriptions_app(bool $enabled = true, int $historySize = 10): array
{
    $app = new App(
        new HttpExceptionConverterMiddleware(),
        new TokenExtractorMiddleware(
            jwt_config()->parser(),
            jwt_config()->validator(),
            accessTokenPolicy: access_token_policy(),
        ),
    );
    $subscribeController = new SubscribeController();
    $hub = new Hub(
        $app,
        new PHPTransport(size: $historySize),
        ['heartbeat_interval' => 0, 'subscriptions' => $enabled],
        [$subscribeController, new SubscriptionsController()],
    );

    return [$app, $hub, $subscribeController];
}

/**
 * A request to the subscription API, by default with a token allowed to read it.
 */
function subscriptions_request(string $path, ?string $jwt = null, array $headers = []): ServerRequest
{
    $jwt ??= access_token([
        detail(['subscribe'], [['match' => '/.well-known/mercure/subscriptions{/*}?', 'match_type' => 'urlpattern']]),
    ]);

    return new ServerRequest('GET', 'http://localhost' . $path, ['Authorization' => "Bearer $jwt", ...$headers]);
}

/**
 * Starts a hub, subscribes to it, publishes an update and returns the received messages.
 */
function publish_and_receive(array $env, string $subscribeQuery, string $publishBody, string $jwt): array
{
    $env = [
        'X_LISTEN' => $_ENV['X_LISTEN'] ?? '127.0.0.1:8080',
        // The CI runs the integration tests against each transport of its matrix.
        'TRANSPORT_DSN' => \getenv('TRANSPORT_DSN') ?: 'php://default',
        ...$env,
    ];
    foreach (\explode(',', $_ENV['SYMFONY_DOTENV_VARS'] ?? '') as $key) {
        $value = $_ENV[$key] ?? null;
        if (null === $value) {
            continue;
        }
        $env[$key] ??= $_ENV[$key];
    }
    $endpoint = \sprintf('http://%s/.well-known/mercure', $env['X_LISTEN']);
    $process = new Process(['bin/freddie',], \dirname(__DIR__), $env);
    $process->start();

    \usleep(1500000); // Wait for process to actually start

    $messages = [];
    $listener = null;
    Loop::addTimer(0.0, function () use ($endpoint, $subscribeQuery, &$messages, &$listener) {
        $listener = new EventSource(\sprintf('%s?%s', $endpoint, $subscribeQuery));
        $listener->on('message', function (MessageEvent $event) use (&$messages) {
            $messages[] = $event->data;
            Loop::stop();
        });
    });
    Loop::addTimer(0.05, function () use ($endpoint, $publishBody, $jwt) {
        HttpClient::create()->request('POST', $endpoint, [
            'body' => $publishBody,
            'headers' => [
                'Authorization' => "Bearer $jwt",
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    });
    $timeout = Loop::addTimer(1, fn() => Loop::stop());
    Loop::run();
    // Otherwise they would interfere with the next hub
    Loop::cancelTimer($timeout);
    $listener?->close();
    $process->stop();

    return $messages;
}
