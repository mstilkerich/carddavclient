# Changelog for CardDAV client library for PHP ("PHP-CardDavClient")

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
