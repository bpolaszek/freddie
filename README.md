[![Application](https://github.com/bpolaszek/mercure-x/actions/workflows/app.yml/badge.svg)](https://github.com/bpolaszek/mercure-x/actions/workflows/app.yml)
[![Coverage](https://codecov.io/gh/bpolaszek/freddie/branch/main/graph/badge.svg?token=uB4gRHyS6r)](https://codecov.io/gh/bpolaszek/freddie)

# Freddie

Freddie is a PHP implementation of the [Mercure Hub Specification](https://mercure.rocks/spec).

It is blazing fast, built on the shoulders of giants:
- [PHP](https://www.php.net/releases/8.4/en.php) 8.4
- [Symfony](https://symfony.com/) 7
- [Redis](https://redis.io/).

See what features are covered and what aren't (yet) [here](#feature-coverage).

## Installation

PHP 8.4+ and Redis 5+ (7+ recommended) are required to run the hub.

By default, Freddie will connect to Redis on `redis://localhost:6379`. You can change this by setting the `TRANSPORT_DSN` environment variable.

### As a standalone Mercure hub

Just run this project as a regular Symfony application. It will expose the following routes:
- `/.well-known/mercure` - the Mercure hub endpoint
- `/.well-known/mercure/subscriptions` - Presence API endpoint

### As a bundle of your existing Symfony application

Add it as a bundle:
```bash
composer req freddie/mercure-x
```

Register the bundle in your `config/bundles.php`:
```php
return [
    // ...
    Freddie\FreddieBundle::class => ['all' => true],
];
```

Then, you can inject `Freddie\Hub\HubInterface` in your services so that you can call `$hub->publish($update)`,
or listening to dispatched updates 👍

The benefit is that publishing updates from your application no longer requires an HTTP request nor authentication to the hub, which is much faster.


### Using Docker

You can run Freddie using Docker. The following command will start the hub with its Redis instance:

```bash
docker compose up
```

### Security 

The default JWT key is `!ChangeThisMercureHubJWTSecretKey!` with a `HS256` signature. 

You can set different values by changing the environment variables (in `.env.local` or at the OS level): 
`JWT_SECRET_KEY`, `JWT_ALGORITHM`, `JWT_PUBLIC_KEY` and `JWT_PASSPHRASE` (when using RS512 or ECDSA)

Please refer to the [authorization](https://mercure.rocks/spec#authorization) section of the Mercure specification to authenticate as a publisher and/or a subscriber.

### Transport

The [official open-source version](https://mercure.rocks/docs/hub/install) of the hub doesn't allow scaling
because of concurrency restrictions on the _bolt_ transport.

Freddie uses Redis streams as the underlying transport mechanism to publish and store updates.

This allows it to scale horizontally, as multiple Freddie instances can share the same Redis instance.

Optional parameters you can pass in the DSN's query string:
- `stream` - the Redis stream name to use (default `freddie`)
- `size` - the maximum number of updates to keep in the stream (default `1000`)
- `maxBufferedItemsPerStream` - the maximum number of items to buffer per stream (default `1000`)
- `blockDurationMs` - the duration in milliseconds to block when waiting for new updates (default `5000`)
- `sleepDurationMs` - the duration in milliseconds to sleep between iterations when waiting for new updates (default `100`)
- `maxIterations` - the maximum number of iterations to perform when waiting for new updates (default `PHP_INT_MAX`)

## Advantages and limitations

This implementation does not provide SSL nor HTTP2 termination, so you'd better put a reverse proxy in front of it.

## Feature coverage

| Feature                                     | Covered                               |
|---------------------------------------------|---------------------------------------|
| JWT through `Authorization` header          | ✅                                     |
| JWT through `authorization` query param     | ✅                                     |
| JWT through `mercureAuthorization` Cookie   | ✅                                     |
| Allow anonymous subscribers                 | ✅                                     |
| Alternate topics                            | ✅️                                    |
| Private updates                             | ✅                                     |
| URI Templates for topics                    | ✅                                     |
| HMAC SHA256 JWT signatures                  | ✅                                     |
| RS512 JWT signatures                        | ✅                                     |
| Environment variables configuration         | ✅                                     |
| Custom message IDs                          | ✅                                     |
| Last event ID (including `earliest`)        | ✅️                                    |
| Customizable event type                     | ✅️                                    |
| Customizable `retry` directive              | ✅️                                    |
| Subscription Events                         | ✅️                                    |
| Presence API                                | ✅️                                    |
| CORS                                        | ❌ (configure them on your web server) |
| Health check endpoint                       | ❌ (PR welcome)                        |
| Logging                                     | ❌ (PR welcome)️                       |
| Metrics                                     | ❌ (PR welcome)️                       |
| Different JWTs for subscribers / publishers | ❌ (PR welcome)                        |


## Tests

This project is 100% covered with [Pest](https://pestphp.com/) tests. 

```bash
composer tests:run
```

## Contribute

If you want to improve this project, feel free to submit PRs:

- CI will yell if you don't follow [PSR-12 coding standards](https://www.php-fig.org/psr/psr-12/)
- In the case of a new feature, it must come along with tests
- [PHPStan](https://phpstan.org/) analysis must pass at level 8

You can run the following command before committing to ensure all CI requirements are successfully met:

```bash
composer ci:check
```

## License

GNU General Public License v3.0.
