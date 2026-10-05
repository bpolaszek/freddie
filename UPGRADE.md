# Upgrading to the Mercure 1.0 protocol

Freddie now implements [version 1.0 of the Mercure protocol](https://mercure.rocks/spec), released in
September 2026. Version 1.0 overhauls the protocol: the way subscribers select topics, the format of the
tokens and the way they are sent all changed. **Mercure 0.x clients and tokens no longer work with the default
configuration.**

This guide explains what changed and how to migrate. You don't have to migrate everything at once: start with
the [compatibility mode](#1-turn-the-compatibility-mode-on), then migrate your token issuer, your publishers and
your subscribers one at a time.

- [Am I affected?](#am-i-affected)
- [Migration path](#migration-path)
  1. [Turn the compatibility mode on](#1-turn-the-compatibility-mode-on)
  2. [Configure the hub identity](#2-configure-the-hub-identity)
  3. [Issue 1.0 access tokens](#3-issue-10-access-tokens)
  4. [Migrate publishers](#4-migrate-publishers)
  5. [Migrate subscribers](#5-migrate-subscribers)
  6. [Turn the compatibility mode off](#6-turn-the-compatibility-mode-off)
- [Other changes](#other-changes)
- [For bundle users and extension authors](#for-bundle-users-and-extension-authors)
- [Checklist](#checklist)

## Am I affected?

Yes if any of the following applies:

| You…                                                                    | What breaks                                               |
|-------------------------------------------------------------------------|-----------------------------------------------------------|
| subscribe with `?topic=…`                                               | `400 Bad Request`: use `match` / `match_urlpattern`       |
| issue JWTs with a `mercure` claim (`mercure.publish`, `mercure.subscribe`) | `401 Unauthorized`: tokens must be RFC 9068 access tokens |
| send the token in the `mercureAuthorization` cookie or the `?authorization=` query parameter | the token is ignored                                      |
| rely on the default `!ChangeMe!` HS256 key                              | the default key changed                                   |
| publish public updates with a token not granting all their topics       | `403 Forbidden`                                           |
| publish with `event=…` or `private=false`                               | `event` is ignored; `private=false` makes the update **private** |
| reconnect with `?lastEventID=…`                                         | ignored: use `last_event_id`                              |
| expect `201 Created` when publishing                                    | publishing answers `200 OK`                               |
| use Freddie as a bundle and implement or call its interfaces           | see [below](#for-bundle-users-and-extension-authors)      |

Your Mercure client libraries need to support the protocol 1.0 too: check their documentation, and keep the
compatibility mode on until they do.

## Migration path

### 1. Turn the compatibility mode on

```dotenv
PROTOCOL_COMPATIBILITY=8
```

In this mode, Freddie accepts both Mercure 0.x (protocol version 8) and 1.0 clients:

| Feature                                   | Compatibility mode | 1.0 mode |
|-------------------------------------------|:------------------:|:--------:|
| `match`, `match_exact`, `match_urlpattern` | ✅                 | ✅       |
| `topic` (exact match or URI Template)     | ✅                 | ❌       |
| `authorization_details` claim             | ✅                 | ✅       |
| `mercure` claim                           | ✅                 | ❌       |
| Any signed JWT (no `typ`, `iss`, `aud`, `exp` required) | ✅   | ❌       |
| `Authorization` header                    | ✅                 | ✅       |
| `__Secure-mercure_access_token` cookie     | ✅                 | ✅       |
| `mercureAuthorization` cookie             | ✅                 | ❌       |
| `authorization` query parameter           | ✅                 | ❌       |
| `last_event_id` query parameter           | ✅                 | ✅       |
| `lastEventID` query parameter             | ✅                 | ❌       |
| `Last-Event-ID` response header            | ✅                 | ❌       |
| `event` field when publishing             | ✅                 | ❌       |
| `private=false` is a public update        | ✅                 | ❌       |
| Public updates only require a `mercure.publish` claim | ✅      | ❌       |

⚠️ The compatibility mode weakens token validation (any signed JWT is accepted, whatever its audience or
expiration claims): don't keep it on longer than necessary.

Some 1.0 behaviors apply in both modes:
- error status codes (see [Errors](#errors));
- publishing answers `200 OK` (it used to be `201 Created`);
- publishing topics in the reserved `/.well-known/mercure` namespace, or the `*` topic, is rejected;
- the default JWT key changed (see [Default JWT key](#default-jwt-key)).

### 2. Configure the hub identity

1.0 access tokens are bound to an issuer and to the hub they are meant for:

```dotenv
# The `iss` claim tokens must carry (any issuer is accepted when empty)
JWT_ISSUER=https://example.com
# The `aud` claim tokens must carry: the public URL of your hub
RESOURCE_IDENTIFIER=https://example.com/.well-known/mercure
```

⚠️ **Set `RESOURCE_IDENTIFIER` in production.** Without it, Freddie derives the audience from the `Host` and
`X-Forwarded-*` request headers, which clients control.

`RESOURCE_IDENTIFIER` is also the base URL that relative URL patterns and topics are resolved against: with it,
`match_urlpattern=/books/:id` matches the topic `https://example.com/books/1`. Without it, relative patterns only
match relative topics.

### 3. Issue 1.0 access tokens

Tokens must now be [RFC 9068](https://www.rfc-editor.org/rfc/rfc9068) access tokens, and grants move from the
`mercure` claim to an [RFC 9396](https://www.rfc-editor.org/rfc/rfc9396) `authorization_details` claim.

**Before** (0.x):

```json
{
  "alg": "HS256",
  "typ": "JWT"
}
{
  "mercure": {
    "publish": ["*"],
    "subscribe": ["https://example.com/books/{id}"],
    "payload": {"user": "https://example.com/users/1"}
  }
}
```

**After** (1.0):

```json
{
  "alg": "HS256",
  "typ": "at+jwt"
}
{
  "iss": "https://example.com",
  "aud": "https://example.com/.well-known/mercure",
  "exp": 1791158400,
  "authorization_details": [
    {
      "type": "https://mercure.rocks/authorization-detail",
      "actions": ["publish"],
      "topics": [{"match": "*"}]
    },
    {
      "type": "https://mercure.rocks/authorization-detail",
      "actions": ["subscribe"],
      "topics": [{"match": "https://example.com/books/:id", "match_type": "urlpattern"}],
      "payload": {"user": "https://example.com/users/1"}
    }
  ]
}
```

What changed:
- the `typ` header must be `at+jwt` (or `application/at+jwt`);
- `iss`, `aud` and `exp` are required, and must match `JWT_ISSUER` (when set) and `RESOURCE_IDENTIFIER`;
- each detail lists `actions` (`publish` and/or `subscribe`) and `topics`;
- topics are objects: `{"match": "…"}` for an exact match, `{"match": "…", "match_type": "urlpattern"}` for a
  [URL pattern](#uri-templates-become-url-patterns). **Bare strings are rejected** (`401`);
- `{"match": "*"}` still grants every topic;
- the payload moves into the subscribe detail it applies to.

For instance with [`lcobucci/jwt`](https://github.com/lcobucci/jwt):

```php
$token = $config->builder()
    ->withHeader('typ', 'at+jwt')
    ->issuedBy('https://example.com')
    ->permittedFor('https://example.com/.well-known/mercure')
    ->expiresAt(new DateTimeImmutable('+1 hour'))
    ->withClaim('authorization_details', [
        [
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['publish'],
            'topics' => [['match' => '*']],
        ],
    ])
    ->getToken($config->signer(), $config->signingKey());
```

Short-lived tokens are now mandatory (`exp`): if your app minted long-lived tokens, it has to renew them.

### 4. Migrate publishers

| 0.x                                         | 1.0                                                                      |
|---------------------------------------------|--------------------------------------------------------------------------|
| `event=alert`                               | `type=alert`                                                             |
| `private=true` / `private=on`               | `private=on` (any value works)                                           |
| `private=false` (public)                    | **omit** the `private` field: its presence alone makes the update private |
| `201 Created`                               | `200 OK`, the update ID as `text/plain`                                   |
| a public update only requires a token       | the token must grant `publish` on **every** topic, alternate topics included |

Updates are now validated, and rejected with `400 Bad Request` when:
- a topic is `*`, or lies in the reserved `/.well-known/mercure` namespace;
- `type` is `mercure` (reserved to the events the hub generates);
- `id` is `earliest`, starts with `#`, or is longer than 1024 bytes;
- a topic, the `id` or the `type` contains control characters, or `data` is not valid UTF-8;
- there are more than 1000 topics.

### 5. Migrate subscribers

#### Selecting topics

`topic` becomes `match` (exact match) or `match_urlpattern` (pattern):

```
# Before
GET /.well-known/mercure?topic=https://example.com/books/1&topic=https://example.com/authors/{id}
# After
GET /.well-known/mercure?match=https://example.com/books/1&match_urlpattern=https://example.com/authors/:id
```

- Parameter names are case-sensitive, and any other parameter starting with `match` is rejected (`400`).
- `match=*` still subscribes to every topic.
- A subscription accepts up to 100 matchers. To work around URL length limits, matchers can be sent in an
  `application/x-www-form-urlencoded` body with the `QUERY` HTTP method.

#### URI Templates become URL patterns

0.x matched topics against [URI Templates](https://www.rfc-editor.org/rfc/rfc6570). 1.0 uses
[URL patterns](https://urlpattern.spec.whatwg.org/) instead, in subscriptions and in tokens alike:

| URI Template (0.x)                         | URL pattern (1.0)                        |
|--------------------------------------------|------------------------------------------|
| `https://example.com/books/{id}`           | `https://example.com/books/:id`          |
| `https://example.com/books/{id}/reviews/{review}` | `https://example.com/books/:id/reviews/:review` |
| `https://example.com/{+path}` (any path)   | `https://example.com/*`                  |
| `https://example.com/books/{id}{?page}`    | `https://example.com/books/:id` (the query is not matched unless the pattern defines one) |

URL patterns also support regular expressions (`:id(\d+)`), optional segments (`:id?`) and groups
(`{/reviews}?`): see the [URL Pattern standard](https://urlpattern.spec.whatwg.org/).

Exact topics need no change: `match=https://example.com/books/1` behaves like `topic=https://example.com/books/1`
did. Note that `match` is a byte-for-byte comparison: a value containing `{` is no longer a template.

#### Authenticating

Send the token in the `Authorization: Bearer …` header or in the `__Secure-mercure_access_token` cookie (the
header takes precedence). Tokens in URLs are forbidden by [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700):
the `authorization` query parameter is gone.

The cookie name can be changed with `COOKIE_NAME`. The `__Secure-` prefix requires the cookie to be set with the
`Secure` attribute, over HTTPS; it should also be `HttpOnly` and `SameSite=Strict`.

#### Reconnecting

- Pass the last event ID in the `Last-Event-ID` header (sent automatically by `EventSource`) or in the
  `last_event_id` query parameter (`lastEventID` is gone).
- The hub answers with a `Mercure-Last-Event-ID` header (it used to be `Last-Event-ID`): the ID of the event
  preceding the first one sent, or `earliest` when the requested ID is unknown, empty or was discarded. When
  you get `earliest` for an ID you sent, some updates may have been lost: reload your data.

### 6. Turn the compatibility mode off

Once all your clients and your token issuer speak 1.0, remove `PROTOCOL_COMPATIBILITY`.

## Other changes

### Errors

Errors now follow [RFC 6750](https://www.rfc-editor.org/rfc/rfc6750), in both modes (the "before" codes are
those of the controllers: in practice, the CORS middleware turned them into `200`, see below):

| Situation                                        | Before | After                                               |
|--------------------------------------------------|--------|-----------------------------------------------------|
| No token where one is required                   | `403`  | `401`, `WWW-Authenticate: Bearer`                   |
| Invalid, expired or malformed token              | `403`  | `401`, `WWW-Authenticate: Bearer error="invalid_token"` |
| Valid token, insufficient grants                 | `403`  | `403`, `WWW-Authenticate: Bearer error="insufficient_scope"` |
| Subscription refusing `text/event-stream` (`Accept`) | `200` | `406`                                               |

⚠️ Before this version, the CORS middleware turned every response into `200 OK`, errors included. Status codes
are now preserved (only `OPTIONS` preflight requests are answered with `200`): if a client relied on errors
coming back as `200`, it will now see the actual status.

### Default JWT key

The default `JWT_SECRET_KEY` changed from `!ChangeMe!` to `!ChangeThisMercureHubJWTSecretKey!`, the default of
the official hub. The former key was too short for HS256 (at least 256 bits are required), so tokens signed with
it could not be validated anyway. Set your own key in production. PEM keys are now refused with HMAC
algorithms.

### Server-sent events format

Fields are now written as `name: value` instead of `name:value`, and `data` is split on every line terminator
(`\r\n`, `\r` and `\n`). `EventSource` and SSE parsers read both forms the same way; only code parsing the raw
stream by hand may be affected.

### Subscription API

With `SUBSCRIPTIONS=true`, Freddie now publishes subscription events and serves the subscription API at
`/.well-known/mercure/subscriptions[/{match_type}[/{match}[/{subscriber}]]]`. Subscription events are private
updates of type `mercure`, on topics like
`/.well-known/mercure/subscriptions/exact/%2Fbooks%2F1/urn%3Auuid%3A…`: subscribing to them requires a subscribe
grant on these topics, e.g. `{"match": "/.well-known/mercure/subscriptions/*", "match_type": "urlpattern"}`.

## For bundle users and extension authors

If you use Freddie as a Symfony bundle, or extend it:

- `HubControllerInterface::getMethod(): string` is replaced by `getMethods(): array`.
- `HubInterface` has new methods: `isLegacyProtocol()`, `getTopicMatcherStore()` and `getSubscribers()`.
- `TransportInterface::reconciliate()` must now **return** whether the requested event was found (`return $found;`
  at the end of the generator). A custom transport that returns nothing replays no missed updates.
- `Subscriber` is built from `Freddie\Matcher\TopicMatcher` objects instead of topic strings, and its ID is a
  `urn:uuid:…` string instead of a `Ulid`. Whether it can receive an update is decided by
  `Subscriber::canReceive()`.
- Authorization is handled by `Freddie\Security\Grants` (`Grants::fromRequest()`), and failures throw
  `Freddie\Security\BearerTokenException`.
- `ChainTokenExtractor` is built with `ChainTokenExtractor::create()`, and `CookieTokenExtractor` reads the
  `__Secure-mercure_access_token` cookie by default.
- `Update::canBePublished()`, `Update::canBeReceived()`, `Freddie\Helper\TopicHelper`, `Freddie\topic()` and
  `Freddie\extract_last_event_id()` are removed: use `Grants`, `Subscriber::canReceive()` and
  `Freddie\Hub\Request\SubscriptionRequest` instead.

The Redis wire format did not change: hubs of both versions can share a Redis instance during a rolling deploy.

## Checklist

- [ ] `PROTOCOL_COMPATIBILITY=8` set during the migration
- [ ] `RESOURCE_IDENTIFIER` (and `JWT_ISSUER`) configured
- [ ] `JWT_SECRET_KEY` set (no reliance on the default key)
- [ ] Token issuer migrated to `at+jwt` tokens with `iss`, `aud`, `exp` and `authorization_details`
- [ ] URI Templates converted to URL patterns, in subscriptions and in tokens
- [ ] Publishers: `event` → `type`, no `private=false`, grants covering every topic, `200` expected
- [ ] Subscribers: `topic` → `match` / `match_urlpattern`, token in the header or the new cookie, `last_event_id`
- [ ] Error handling updated for `401` / `403` / `406`
- [ ] `PROTOCOL_COMPATIBILITY` removed
