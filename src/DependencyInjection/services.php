<?php

declare(strict_types=1);

namespace Freddie\DependencyInjection;

use Clue\React\Redis\Factory;
use DateTimeZone;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use FrameworkX\App;
use Freddie\Hub\Controller\SubscribeController;
use Freddie\Hub\Hub;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Hub\Middleware\CorsMiddleware;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Hub\Transport\TransportFactory;
use Freddie\Hub\Transport\TransportFactoryInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Freddie\Security\JWT\Configuration\DateTimeZoneFactory;
use Freddie\Security\JWT\Configuration\SignerFactory;
use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Configuration\VerificationKeyFactory;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Freddie\Security\JWT\Validation\AccessTokenPolicy;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validator;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container) {
    $params = $container->parameters();
    $params->set('env(TRANSPORT_DSN)', 'php://default');
    $params->set('env(ALLOW_ANONYMOUS)', Hub::DEFAULT_OPTIONS['allow_anonymous']);
    $params->set('env(HEARTBEAT_INTERVAL)', Hub::DEFAULT_OPTIONS['heartbeat_interval']);
    $params->set('env(JWT_SECRET_KEY)', '!ChangeThisMercureHubJWTSecretKey!');
    $params->set('env(JWT_PUBLIC_KEY)', null);
    $params->set('env(JWT_ALGORITHM)', 'HS256');
    $params->set('env(PROTOCOL_COMPATIBILITY)', '');
    $params->set('env(SUBSCRIPTIONS)', Hub::DEFAULT_OPTIONS['subscriptions']);
    $params->set('env(JWT_ISSUER)', '');
    $params->set('env(RESOURCE_IDENTIFIER)', '');
    $params->set('env(COOKIE_NAME)', ChainTokenExtractor::COOKIE_NAME);
    $params->set('transport_dsn', '%env(resolve:TRANSPORT_DSN)%');
    $params->set('allow_anonymous', '%env(bool:ALLOW_ANONYMOUS)%');
    $params->set('heartbeat_interval', '%env(float:HEARTBEAT_INTERVAL)%');
    $params->set('protocol_compatibility', '%env(PROTOCOL_COMPATIBILITY)%');
    $params->set('legacy_protocol', '%env(bool:PROTOCOL_COMPATIBILITY)%');
    $params->set('subscriptions', '%env(bool:SUBSCRIPTIONS)%');
    $params->set('resource_identifier', '%env(RESOURCE_IDENTIFIER)%');

    $services = $container->services();
    $services
        ->defaults()
        ->private()
        ->autoconfigure()
        ->autowire()
        ->bind('iterable $controllers', tagged_iterator('mercure.controller'))
    ;

    $services
        ->instanceof(HubControllerInterface::class)
        ->tag('mercure.controller');

    $services
        ->instanceof(TransportFactoryInterface::class)
        ->tag('mercure.transport_factory');

    $services
        ->instanceof(Constraint::class)
        ->tag('lcobucci.jwt.validation_constraint');

    $services
        ->load('Freddie\\', dirname(__DIR__))
        ->exclude([
            dirname(__DIR__) . '/DependencyInjection/*',
            dirname(__DIR__) . '/Hub/Transport/Redis/RedisPublisher.php',
            dirname(__DIR__) . '/Hub/Transport/Redis/RedisListener.php',
            dirname(__DIR__) . '/Hub/Transport/Redis/RedisTransport.php',
            dirname(__DIR__) . '/FreddieBundle.php',
            dirname(__DIR__) . '/functions.php',
            dirname(__DIR__) . '/Kernel.php',
            // Applied per request by the AccessTokenPolicy, not globally.
            dirname(__DIR__) . '/Security/JWT/Validation/AccessTokenConstraint.php',
        ]);

    $services
        ->set(SubscribeController::class);

    $services
        ->set(ChainTokenExtractor::class)
        ->factory([ChainTokenExtractor::class, 'create'])
        ->args([param('legacy_protocol'), param('env(COOKIE_NAME)')]);

    $services
        ->alias(PSR7TokenExtractorInterface::class, ChainTokenExtractor::class);

    $services
        ->set(AccessTokenPolicy::class)
        ->args([param('legacy_protocol'), param('env(JWT_ISSUER)'), param('resource_identifier')]);

    $services
        ->set(TopicMatcherStore::class)
        ->args([param('resource_identifier')]);

    $services
        ->set(TransportFactory::class)
        ->arg('$factories', tagged_iterator('mercure.transport_factory'));

    $services
        ->set(TransportInterface::class)
        ->factory([service(TransportFactory::class), 'create'])
        ->arg('$dsn', param('transport_dsn'));

    $services
        ->set(Hub::class)
        ->arg('$options', [
            'allow_anonymous' => param('allow_anonymous'),
            'heartbeat_interval' => param('heartbeat_interval'),
            'protocol_compatibility' => param('protocol_compatibility'),
            'subscriptions' => param('subscriptions'),
        ]);

    $services->alias(HubInterface::class, Hub::class);

    $services
        ->set(ValidationConstraints::class)
        ->arg('$validationConstraints', tagged_iterator('lcobucci.jwt.validation_constraint'));

    $services
        ->set(App::class)
        ->args([
            service(CorsMiddleware::class),
            service(HttpExceptionConverterMiddleware::class),
            service(TokenExtractorMiddleware::class),
        ]);

    $services
        ->set(EventEmitter::class);

    $services
        ->alias(EventEmitterInterface::class, EventEmitter::class);

    $services
        ->set(Factory::class);

    $services
        ->set(CorsMiddleware::class)
        ->args([
            param('env(string:default::CORS_ORIGINS)')
        ]);

    $services
        ->set(Configuration::class)
        ->factory(service(ConfigurationFactory::class))
        ->args([
            param('env(JWT_ALGORITHM)'),
            param('env(resolve:JWT_SECRET_KEY)'),
            param('env(string:default::resolve:JWT_PUBLIC_KEY)'),
            param('env(string:default::JWT_PASSPHRASE)'),
        ]);

    $services
        ->set(Parser::class)
        ->factory([service(Configuration::class), 'parser']);

    $services
        ->set(Validator::class)
        ->factory([service(Configuration::class), 'validator']);

    $services
        ->set('jwt.verification_key')
        ->class(Key::class)
        ->factory(service(VerificationKeyFactory::class));

    $services
        ->set(DateTimeZone::class)
        ->class(DateTimeZone::class)
        ->factory(service(DateTimeZoneFactory::class));

    $services
        ->set(Clock::class)
        ->args([
            '$timezone' => service(DateTimeZone::class),
        ]);

    $services
        ->set(Signer::class)
        ->factory(service(SignerFactory::class));

    $services
        ->set(Constraint\LooseValidAt::class);

    $services
        ->set(Constraint\SignedWith::class)
        ->arg('$key', service('jwt.verification_key'));
};
