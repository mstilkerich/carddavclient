# Known Features and Quirks of CardDAV server implementations / public CardDAV services

While CardDAV is a standardized protocol, not all services implement it properly. Be particularly warned when
interacting with Google Contacts. During the development of this library and particularly with the interoperability
tests that run against various server implementations and public services, I discovered various quirks with these
implementations. The library tries to work around them to the possible extent, but some cannot be worked around and will
inevitably be visible to the user.

For reference, this file documents everything I discovered so far to that end. I will also provide an overview on the
supported CardDAV server features.

## Feature Matrix

## Known Quirks

### Google Contacts (CardDAV interface)

- sync-collection REPORT requires sync-token
- UID property of new VCards is overwritten
- TYPE attribute is converted to uppercase for known types (HOME, WORK), discarded for unknown types; other type labels
  are possible but require use of X-ABLabel extension
- X-SERVICE-TYPE parameter lower/uppercase spelling is also adapted to Google's preference (e.g. jabber -> Jabber)

### Sabre/DAV

- Internal server error on REPORTs with digest authentication
- Does not support addressdata filter in multiget REPORT
  - https://github.com/sabre-io/dav/pull/1310
- Internal server error on param-filter when the server encounters a property of the filtered for type that does not have
  the asked for parameter at all
  - https://github.com/sabre-io/dav/pull/1322

### Davical

- addressbook-query on EMAIL address returns empty result for cards with several EMAIL properties (probably also for other properties)
  - https://gitlab.com/davical-project/awl/-/issues/20
- addressbook-query with param-filter returns wrong results, because davical matches on the property value, not the parameter value;
  furthermore, param-filter in davical performs case-sensitive contains matching, i.e. the collation and matcht-type are ignored.
  - https://gitlab.com/davical-project/awl/-/issues/21



### Radicale

- addressbook-query does not accept is-not-defined element in prop-filter
  - https://github.com/Kozea/Radicale/pull/1139
