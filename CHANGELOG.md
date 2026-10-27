# Changelog for CardDAV client library for PHP ("PHP-CardDavClient")

## Version 2.0.0 (to 1.4.2)

- Security: The credentials of an account are only sent to the origin (scheme, host and port) of the URL of the
  resource a request is made for. Previously, credentials were sent to any host whose last two domain name labels
  matched those of the resource, e.g. from `a.co.uk` to `evil.co.uk`, or for IP addresses, to any IP address with the
  same last two octets. Credentials are never sent over plain HTTP if the resource uses HTTPS.
- New HTTP option `auth_origin_callback` for an `Account`: A callback that decides whether the credentials may be sent
  to a different origin. It is not included in the serialized form of the Account; use the new method
  `Account::setAuthOriginCallback()` to set it after deserialization.
- Discovery: Credentials are sent to servers within the discovery domain (the domain and its subdomains) only. Other
  servers, e.g. targets of redirects, addressbook homes on other domains and DNS SRV targets outside the discovery
  domain, require permission by the `auth_origin_callback`. See `Discovery::discoverAddressbooks()` for details.
- `WebDavCollection::getChildren()` only returns direct members of the collection (same origin, path of the collection
  plus one path segment). If the collection was redirected, the collection itself is no longer returned as a child.
- Documentation: Creating a `WebDavResource` for a URI permits sending the account's credentials to the origin of the
  URI. Account-level `headers` are sent to all origins.
- Remove the discovery workaround for iCloud (context path `/co`), which is not needed anymore.
- Support result limiting for the sync-collection REPORT (RFC 6578 `DAV:limit`): `Sync::synchronize()` and
  `CardDavClient::syncCollection()` take an optional `$limit` parameter. With a limit, `Sync::synchronize()` retrieves
  the changes in multiple batches until all changes are synchronized.
  If the server rejects the limit or does not support the sync-collection REPORT, the ETag-based synchronization fetches
  and reports the changed cards in batches of the requested size.
- `Sync::synchronize()` stops requesting followup batches if the server reports a truncated result but does not
  advance the sync-token.
- `SyncHandler::finalizeSync()` is called only once at the end of `Sync::synchronize()`, as documented. Previously,
  it was called after each batch if the server truncated the result of the sync-collection REPORT.
- `Sync::synchronize()` no longer aborts if a changed card cannot be retrieved or its address data is not a VCard (for
  example, a card deleted on the server during the sync). As documented, the card is reported to
  `SyncHandler::addressObjectChanged()` with a null VCard. Previously, an exception aborted the sync, or a non-VCard
  object was passed to the handler, causing a TypeError.
- `AddressbookCollection::deleteCard()` takes an optional ETag to make the deletion conditional (`If-Match`), like
  `updateCard()`, and returns false instead of deleting if the card changed on the server in the meantime.
- A request the server answers with an unexpected HTTP status now throws an `HttpStatusException`, which provides the
  status code (`getStatusCode()`). It extends `\Exception` and its message is unchanged, so existing code is not
  affected.
- Require PHP 8.2 or newer. Support for the end-of-life PHP versions 7.1 - 8.1 is dropped. Tested with PHP 8.2 - 8.5.
- Support newer major versions of dependencies: guzzlehttp/guzzle 8, sabre/vobject 5, sabre/uri 3.
- Drop support for old major versions of dependencies: guzzlehttp/guzzle 6, psr/http-message 1, psr/log 1,
  sabre/vobject 3, sabre/xml 2. The new dependency constraints are:
  - guzzlehttp/guzzle: ^7.9 || ^8.0
  - psr/http-message: ^2.0
  - psr/log: ^2.0 || ^3.0
  - sabre/vobject: ^4.5 || ^5.0
  - sabre/xml: ^3.0 || ^4.0
  - sabre/uri: ^2.3 || ^3.0
- Guzzle 8: Digest and NTLM authentication are performed via curl (as with Guzzle 7), since Guzzle 8's own Digest
  implementation fails for REPORT requests to Sabre/DAV based servers and Guzzle 8 dropped NTLM support.
- Add options for TLS authentication passed through to Guzzle (#38, thanks @mintsoft)
- In case unrequested XML elements are encountered in the XML response of the CardDAV server, only log a debug message
  instead of a warning.
- Fix ordering of DNS SRV records according to RFC 2782 in case multiple SRV records are returned.
- Fix fallback to plain http (`_carddav._tcp`) DNS SRV discovery (if explicitly allowed via protocol spec)

## Version 1.4.2 (to 1.4.1)

- Fix: When the URL of the collection was given without a trailing slash, some operations (e.g.
  AddressbookCollection::createCard) would target the wrong URL (stripping the last component of the base URI).
  Typically, this would result in the operation to fail because the CardDAV server did not allow the operation for the
  wrong URI (Fixes #35).
- Changed discovery behavior: The discovery URL is now tried first, before attempting a discovery via DNS SRV/TXT and
  /well-known/ URI. This is based on the problem that a user can explicitly give the discovery URI in the Account, but
  it would never be tried if the auto-discovery meachanmisms yield a record. It can make a difference in special cases,
  i.e. when a single domain hosts multiple carddav services under different URIs, and the discovery could only be set up
  for one of them. Then even when the user gave a specific service URI as discovery URI, it would only be tried after
  the auto-discovery had failed for the others. This is probably not intended and thus this is considered a bugfix.
- Add Nextcloud user_oidc authorization support via Bearer (#34, thanks @Zepmann)
- Fix PHP 8.4 deprecation warnings (#33, thanks @fwiep)

## Version 1.4.1 (to 1.4.0)

- Report requests to Sabre/DAV servers with Http-Digest authentication failed if issued from an
  AddressbookCollection object that was not use for any other (non REPORT) requests before (Fixes #27).

## Version 1.4.0 (to 1.3.0)

- Support servers with multiple addressbook home locations for one principal in the Discovery service.
- Support configuration of server SSL certificate validation against custom CA (or disable verification)
- Support preemptive basic authentication, i.e. send basic authentication Authorization header even if not requested by
  server. This is useful in rare use cases where the server allows unauthenticated access and would not challenge the
  client. It might also be useful to reduce the number of requests if the authentication scheme is known to the client.
- Support specifying additional HTTP headers and query string options to be used with every request sent in association
  with an account.

## Version 1.3.0 (to 1.2.3)

- New APIs AddressbookCollection::getDisplayName() and AddressbookCollection::getDescription()
- Widen dependency on psr/log to include all v1-v3 versions. To enable this, remove dev-dependency on wa72/simplelogger
  (Fixes #23).

## Version 1.2.3 (to 1.2.2)

- Fix: Throw an exception in the Discovery service in case no addressbook home could be discovered. Previously, an empty
       list would be returned without indication that the discovery was not successful.
- Fix: After failure to authenticate with the server, the CardDavClient object might be left in a state that causes a
       PHP warning on next usage (a property of the object was unintentionally deleted in that case and the warning
       would be triggered on next attempt to access that property).

## Version 1.2.2 (to 1.2.1)

- Config::init() now accepts an options array as third parameter, which currently allows to customize the log format for
  the HTTP logs. It is meant to be extended with further options in the future.
- Use CURLAUTH_NEGOTIATE only when curl supports SPNEGO (Fixes #20)

## Version 1.2.1 (to 1.2.0)

- Change license to less restrictive MIT license
- Add workaround to enable Bearer authentication with yahoo CardDAV API (#14, thanks @DrFairy)

## Version 1.2.0 (to 1.1.0)

- Support for OAUTH2/Bearer authentication. Specify bearertoken in credentials when creating Account. Acquiring the
  access token is outside the scope of this library.
- The interface for specifying credentials for an Account changed. The old username/password parameters are deprecated,
  but still work.

## Version 1.1.0 (to 1.0.0)

- New API AddressbookCollection::query() for server-side addressbook search
- Generated API documentation for the latest release is now published to
  [github pages](https://mstilkerich.github.io/carddavclient/)

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
