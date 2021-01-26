# Known Features and Quirks of CardDAV server implementations / public CardDAV services

While CardDAV is a standardized protocol, not all services implement it properly. Be particularly warned when
interacting with Google Contacts. During the development of this library and particularly with the interoperability
tests that run against various server implementations and public services, I discovered various quirks with these
implementations. The library tries to work around them to the possible extent, but some cannot be worked around and will
inevitably be visible to the user.

For reference, this file documents everything I discovered so far to that end. I will also provide an overview on the
supported CardDAV server features.

## Feature Matrix

- iCloud apparently does not support param-filter. It simply ignores the filter and returns all cards that match the
  remaining conditions, i.e. at least that the property that contains the param-filter is defined is used as filter.

## Known Quirks

### Google Contacts (CardDAV interface)

- `BUG_REJ_EMPTY_SYNCTOKEN` sync-collection REPORT requires sync-token
  - https://issuetracker.google.com/issues/160190530
- UID property of new VCards is overwritten
- TYPE attribute is converted to uppercase for known types (HOME, WORK), discarded for unknown types; other type labels
  are possible but require use of X-ABLabel extension
- X-SERVICE-TYPE parameter lower/uppercase spelling is also adapted to Google's preference (e.g. jabber -> Jabber)
- `BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS` see Davical
  - https://issuetracker.google.com/issues/178251714
- `BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS` A prop-filter that filters on a parameter that does not match a text will
  also match cards that a) don't have the property, or b) have the property, but without the parameter.
  - https://issuetracker.google.com/issues/178251714
- `BUG_INVTEXTMATCH_SOMEMATCH`: see Sabre/DAV
- param-filter with is-not-defined subfilter matches cards that don't have the property defined. However, for the
  enclosing prop-filter to match, presence of the property is mandatory.
  - https://issuetracker.google.com/issues/178243204

### Sabre/DAV (used by Owncloud, Nextcloud, Baïkal)

- Internal server error on REPORTs with digest authentication
- Does not support addressdata filter in multiget REPORT
  - https://github.com/sabre-io/dav/pull/1310
- `BUG_PARAMFILTER_ON_NONEXISTENT_PARAM` Internal server error on param-filter when the server encounters a property of
  the filtered for type that does not have the asked for parameter at all
  - https://github.com/sabre-io/dav/pull/1322
- inverted text-match of a param-filter yields wrong results if there is a property matching the text-match and another
  that does not. This is because sabre will simply invert the result of checking all properties, when it should check if
  there is any property NOT matching the text-filter (!= NO property matching the text filter)
  - https://github.com/sabre-io/dav/pull/1322
- `BUG_INVTEXTMATCH_SOMEMATCH` inverted text-match of a prop-filter yields wrong results if there is a property instance
  matching the text-match and another that does not. This is because sabre will simply invert the result of checking all
  properties, when it should check if there is any property NOT matching the text-filter (!= NO property matching the
  text filter)
  - https://github.com/sabre-io/dav/pull/1322

### Davical

- addressbook-query on EMAIL address returns empty result for cards with several EMAIL properties (probably also for
  other properties)
  - https://gitlab.com/davical-project/awl/-/issues/20
- addressbook-query with param-filter returns wrong results, because davical matches on the property value, not the
  parameter value; furthermore, param-filter in davical performs case-sensitive contains matching, i.e. the collation
  and match-type are ignored.
  - https://gitlab.com/davical-project/awl/-/issues/21
  - https://gitlab.com/davical-project/awl/-/merge_requests/17
- `BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS` addressbook-query with prop-filter containing a negated text-match: Davical
  also returns cards that do not have the asked for property. Example: If you filter for an EMAIL that does not match
  /foo/, Davical will return cards that do not have an EMAIL property at all. Same for param-filter: If the parameter
  does not exist, the param-filter should fail, the text-match does not matter.
  - https://gitlab.com/davical-project/awl/-/merge\_requests/15
  - https://gitlab.com/davical-project/awl/-/merge\_requests/18
- `BUG_PARAMNOTDEF_SOMEMATCH` addressbook-query with a param-filter for a not-defined parameter yields wrong results for
  cards where the parameter is present for some properties, but not all (it must not be present at all for a match)
  - https://gitlab.com/davical-project/awl/-/merge\_requests/16
- Davical does not support multiple prop-filter subfilters, it ignores all but the first one


### Radicale (used by Synology Contacts App)

- addressbook-query does not accept is-not-defined element in prop-filter
  - https://github.com/Kozea/Radicale/pull/1139
- `BUG_INVTEXTMATCH_SOMEMATCH`: see Sabre/DAV
  - https://github.com/Kozea/Radicale/issues/1140
- Radicale ignores the test attribute on a prop-filter and applies "allof" semantics (even though the default would be
  anyof)
  - https://github.com/Kozea/Radicale/issues/1143

### iCloud

- Does not support allof test of multiple prop-filters (i.e. test="allof" at the filter level). It does support allof
  matching at the prop-filter level.
