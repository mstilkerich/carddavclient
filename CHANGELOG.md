# Changelog for CardDAV client library for PHP ("PHP-CardDavClient")

## Unreleased (to 1.2.1)

- Option to set loglevel for HTTP requests in Config. The prior verbose log format will only be used if the level is set
  to debug. A shorted version without the full requests/responses is used for other loglevels.

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
