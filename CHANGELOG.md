# Changelog for CardDAV client library for PHP ("PHP-CardDavClient")

## Version 1.X.Y (to 1.2.2)

- Fix: Throw an exception in the Discovery service in case no addressbook home could be discovered. Previously, an empty
       list would be returned without indication that the discovery was not successful.

## Version 1.2.2 (to 1.2.1)

- Config::init() now accepts an options array as third parameter, which currently allows to customize the log format for
  the HTTP logs. It is meant to be extended with further options in the future.
- Use CURLAUTH_NEGOTIATE only when curl supports SPNEGO (Fixes #20)

## Version 1.2.1 (to 1.2.0)

- Change license to less restrictive MIT license
- Add workaround to enable Bearer authentication with yahoo CardDAV API (#14)

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
