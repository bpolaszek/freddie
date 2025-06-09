<?php

declare(strict_types=1);

namespace Freddie\Resources\config;

use DateTimeZone;
use Freddie\Hub\Hub;
use Freddie\Hub\HubInterface;
use Freddie\Hub\HubOptions;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Freddie\Security\JWT\Configuration\DateTimeZoneFactory;
use Freddie\Security\JWT\Configuration\SignerFactory;
use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\TokenExtractorInterface;
use Freddie\Transport\Redis\LazyRedis;
use Freddie\Transport\Redis\RedisTransport;
use Freddie\Transport\TransportInterface;
use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Decoder;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Parser as ParserInterface;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Lcobucci\JWT\Validator as ValidatorInterface;
use Psr\Clock\ClockInterface;
use Redis;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container) {
    $params = $container->parameters();
    $params->set('env(TRANSPORT_DSN)', 'redis://localhost');
    $params->set('env(ALLOW_ANONYMOUS)', HubOptions::DEFAULTS[HubOptions::ALLOW_ANONYMOUS]);
    $params->set('env(SUBSCRIPTIONS)', HubOptions::DEFAULTS[HubOptions::SUBSCRIPTIONS]);
    $params->set('env(JWT_SECRET_KEY)', '!ChangeMe!');
    $params->set('env(JWT_PUBLIC_KEY)', null);
    $params->set('env(JWT_ALGORITHM)', 'HS256');
    $params->set('freddie.transport_dsn', '%env(resolve:TRANSPORT_DSN)%');
    $params->set('freddie.allow_anonymous', '%env(bool:ALLOW_ANONYMOUS)%');
    $params->set('freddie.subscriptions', '%env(bool:SUBSCRIPTIONS)%');
    $params->set('freddie.jwt_secret_key', '%env(resolve:JWT_SECRET_KEY)%');
    $params->set('freddie.jwt_public_key', '%env(string:default::resolve:JWT_PUBLIC_KEY)%');
    $params->set('freddie.jwt_passphrase', '%env(string:default::JWT_PASSPHRASE)%');
    $params->set('freddie.jwt_algorithm', '%env(JWT_ALGORITHM)%');

    $services = $container->services();
    $services
        ->defaults()
        ->private()
        ->autoconfigure()
        ->autowire();

    $services
        ->load('Freddie\\', dirname(__DIR__, 2))
        ->exclude([
            dirname(__DIR__, 2) . '/Resources/*',
            dirname(__DIR__, 2) . '/FreddieBundle.php',
            dirname(__DIR__, 2) . '/functions.php',
            dirname(__DIR__, 2) . '/Kernel.php',
        ]);

    $services
        ->instanceof(Constraint::class)
        ->tag('lcobucci.jwt.validation_constraint');


    $services->alias(HubInterface::class, Hub::class);
    $services->alias(TransportInterface::class, RedisTransport::class);
    $services->alias(TokenExtractorInterface::class, ChainTokenExtractor::class);
    $services
        ->set(LazyRedis::class)
        ->factory([LazyRedis::class, 'factory'])
        ->arg('$dsn', param('freddie.transport_dsn'));

    // Redis
    $services->set(Redis::class, Redis::class);

    // lcobucci/jwt
    $services->set(JoseEncoder::class);
    $services->alias(Decoder::class, JoseEncoder::class);
    $services->set(Parser::class);
    $services->alias(ParserInterface::class, Parser::class);
    $services->set(Validator::class);
    $services->alias(ValidatorInterface::class, Validator::class);

    $services
        ->set(Configuration::class)
        ->factory(service(ConfigurationFactory::class))
        ->arg('$algorithm', param('freddie.jwt_algorithm'))
        ->arg('$secretKey', param('freddie.jwt_secret_key'))
        ->arg('$publicKey', param('freddie.jwt_public_key'))
        ->arg('$passphrase', param('freddie.jwt_passphrase'));

    $services
        ->set('freddie.verification_key')
        ->class(Key::class)
        ->factory([service(Configuration::class), 'verificationKey']);

    $services
        ->set(SignedWith::class)
        ->arg('$key', service('freddie.verification_key'));

    $services
        ->set(DateTimeZone::class)
        ->class(DateTimeZone::class)
        ->factory(service(DateTimeZoneFactory::class));

    $services
        ->set(SystemClock::class)
        ->args([
            service(DateTimeZone::class),
        ]);

    $services->set(ClockInterface::class, SystemClock::class);
    $services->alias(Clock::class, SystemClock::class);

    $services
        ->set(Signer::class)
        ->factory(service(SignerFactory::class));

    $services->set(LooseValidAt::class);

    $services->set(Constraint\HasClaim::class)
        ->arg('$claim', 'mercure');

    $services->set(ValidationConstraints::class)
        ->arg('$validationConstraints', tagged_iterator('lcobucci.jwt.validation_constraint'));
    ;
};
