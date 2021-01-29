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
- Radicale and iCloud do not support client-side requested limit of results in addressbook-query report. According to
  RFC 6352, this is ok as the server may disregard a client-side requested limit.
- Sabre/DAV does not support addressdata filter in multiget REPORT ([PR](https://github.com/sabre-io/dav/pull/1310))

## Known issues and quirks of CardDAV server implementations

#### Empty synctoken not accepted for initial sync-collection report (BUG_REJ_EMPTY_SYNCTOKEN)
**Affected servers / services**: [Google Contacts](https://issuetracker.google.com/issues/160190530)
<details>
  <summary>Details</summary>

**Description**: For the initial sync, the server must accept an empty sync-token and consequently report all address objects within the addressbook collection in its result, plus a sync-token to be used for follow-up syncs. The server rejects a sync-collection report request carrying an empty sync-token with `400 Bad Request`.

**Affected operations**: `Sync::synchronize()` when called with an empty `$prevSyncToken` parameter.

**User-visibile impact and possible workaround**: Carddavclient will transparently fall back to a slower synchronization method based on `PROPFIND`. Carddavclient will ask the server for a synctoken that can be used for future incremental syncs using the sync-collection report. A log message with loglevel *error* will be logged.
</details>

#### Depth: 0 header rejected for sync-collection report
**Affected servers / services**: [Google Contacts](https://issuetracker.google.com/issues/160190530)
<details>
  <summary>Details</summary>

**Description**: According to RFC 6578, a `Depth: 0` header MUST be used with a sync-collection REPORT request, otherwise the server must reject it as `400 Bad Request`. The Google Contacts API seems to interpret this header for the depth of the request (which is per RFC 6578 given in the `DAV:sync-level` element). As a consequence, the response from Google Contacts will always appear as if there had been no changes. Using a `Depth: 1` header returns the expected result, but a CardDAV client cannot use this as this must be expected to fail with RFC compliant server implementations.

**Affected operations**: `Sync::synchronize()`

**User-visibile impact and possible workaround**: Carddavclient transparently works around the problem by specifically sending a `Depth: 1` header for addressbooks under the `www.googleapis.com` domain. For all other domains, the library will send a `Depth: 0` header in compliance with RFC 6578.
</details>

#### UID of created VCard reassigned by server
**Affected servers / services**: Google Contacts
<details>
  <summary>Details</summary>

**Description**: This is not a bug, but something the user should be aware of. Every VCard stored to a CardDAV server requires a `UID` property. When a new card is stored to Google Contacts, the server will replace the `UID` that is stored in the card with one assigned by the server.

**Affected operations**: `AddressbookCollection::createCard()`

**User-visibile impact and possible workaround**: The user must not assume that a newly created card will retain the UID assigned by the client application. If the UID is stored locally, for example to map locally cached cards against those retrieved from the server, the user should download the card after creation and use the UID property from the retrieved vcard.
</details>

#### Stored VCard modified by server
**Affected servers / services**: Google Contacts
<details>
  <summary>Details</summary>

**Description**: This is probably within what the server is allowed to do, but something the user should be aware of. Google Contacts will modify VCards stored to the server, probably "lost in translation" to an internal data model and back. Currently, so following have been observed:

- The `TYPE` parameter that can be used with properties such as `EMAIL` is constrained to a single value. Values not known to Google Contacts are discarded. This includes values explicitly allowed by RFC2426, e.g. *internet* as an `EMAIL` type.
- For `IMPP` properties, the protocol scheme and `X-SERVICE-TYPE` parameter spelling (e.g. *jabber* becomes *Jabber*) is adapted by the server.

**Affected operations**: `AddressbookCollection::createCard()`, `AddressbookCollection::updateCard()`

**User-visibile impact and possible workaround**: The user should not expect a VCard stored to the server to be identical with the VCard read back from the server. To preserve custom labels on the server, the `X-ABLabel` extension can be used, however, support by CardDAV client applications is not as good as for the `TYPE` parameter.
</details>

### Issues/quirks specific to addressbook query

Because there are so many issues concerning the handling of the addressbook-query report, these are grouped in this section. Most problems concern the use of negated text matches or parameter filters, and thus can be avoided by not using such filters.

#### Negated text matches yield results the lack the matched property/parameter (BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS)
**Affected servers / services**: [Google Contacts](https://issuetracker.google.com/issues/178251714), [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/15)
<details>
  <summary>Details</summary>

**Description**: When issuing an addressbook-query with a prop-filter containing a negated text-match, the server also returns cards that lack the asked for property. Example: If you filter for an `EMAIL` with a `!/foo/` text filter, the server will return cards that do not have an `EMAIL` property at all.

**Affected operations**: `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a property.

**User-visibile impact and possible workaround**: The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.
</details>

#### Negated text matches on parameter yield results the lack the matched property/parameter (BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS)
**Affected servers / services**: [Google Contacts](https://issuetracker.google.com/issues/178251714), [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/18)
<details>
  <summary>Details</summary>

**Description**: When issuing an addressbook-query with a param-filter containing a negated text-match, the server also returns cards that lack the asked for property or parameter. Example: If you filter for an `EMAIL;TYPE` with a `!/foo/` text filter, the server will return cards that
  * do not have an `EMAIL` property at all, or
  * have an `EMAIL` property that lacks the `TYPE` parameter

**Affected operations**: `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a parameter.

**User-visibile impact and possible workaround**: The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.
</details>

#### Negated text match misses cards that also have property instances matching the non-negated filter (BUG_INVTEXTMATCH_SOMEMATCH)
**Affected servers / services**: Google Contacts, [Sabre/DAV](https://github.com/sabre-io/dav/pull/1322)
<details>
  <summary>Details</summary>

**Description**: A negated text-match on a property yields wrong results if there is a property instance matching the text-match and another that does not. This is because the server will simply invert the result of checking all properties, when it should check if there is any property NOT matching the text-filter (!= NO property matching the text filter).

**Affected operations**: `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a property.

**User-visibile impact and possible workaround**: The `query()` result may lack cards that match the filter when using negated text matches on properties.
</details>

#### Filtering for not-defined parameters yields cards that lack the enclosing property
**Affected servers / services**: [Google Contacts](https://issuetracker.google.com/issues/178243204)
<details>
  <summary>Details</summary>

**Description**: When filtering for a not-defined parameter (e.g. `'EMAIL' => '['TYPE' => null]`), the server returns cards that do not have the enclosing property. However, in such cases, the param-filter should have no relevance. So in the example, the server would return cards that do not have an `EMAIL` property.

**Affected operations**: `AddressbookCollection::query()` when using filters for not-defined parameters.

**User-visibile impact and possible workaround**: The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.
</details>

#### Text matches against multi-value parameters are not matched against the individual values (BUG_MULTIPARAM_NOINDIVIDUAL_MATCH)
**Affected servers / services**: Google Contacts, Sabre/DAV, [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/19)
<details>
  <summary>Details</summary>

**Description**: A param-filter text-match for a parameter with multiple values (e.g. `TYPE=HOME,WORK`) will not match against the individual parameter values, but as the parameter string as a whole. For example an equals text-match for `/HOME/=` would not match the example given before.

**Affected operations**: `AddressbookCollection::query()` when querying parameters that may have multiple values.

**User-visibile impact and possible workaround**: The `query()` result may lack cards that would have matched the filter. By using contains text matches (e.g. `/HOME/`), the effect can be avoided (of course the results might differ in that case).
</details>


### Google Contacts (CardDAV interface)
- `BUG_CASESENSITIVE_NAMES`: See Davical; Google also treats names of parameters case sensitive

### Sabre/DAV (used by Owncloud, Nextcloud, Baïkal)

- Internal server error on REPORTs with digest authentication

- `BUG_PARAMFILTER_ON_NONEXISTENT_PARAM` Internal server error on param-filter when the server encounters a property of
  the filtered for type that does not have the asked for parameter at all
  - https://github.com/sabre-io/dav/pull/1322
- inverted text-match of a param-filter yields wrong results if there is a property matching the text-match and another
  that does not. This is because sabre will simply invert the result of checking all properties, when it should check if
  there is any property NOT matching the text-filter (!= NO property matching the text filter)
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
- `BUG_PARAMNOTDEF_SOMEMATCH` addressbook-query with a param-filter for a not-defined parameter yields wrong results for
  cards where the parameter is present for some properties, but not all (it must not be present at all for a match)
  - https://gitlab.com/davical-project/awl/-/merge\_requests/16
- Davical does not support multiple prop-filter subfilters, it ignores all but the first one
  - https://gitlab.com/davical-project/awl/-/merge\_requests/19
- `BUG_CASESENSITIVE_NAMES`: In addressbook-query, Davical treats property and group names case sensitive, i.e. when
  search for a property `email` it will not match an `EMAIL` property in a VCard. However, according to RFC 6350,
  names of properties and groups are case insensitive. Parameter names in param-filter appear to be correctly treated
  as case insensitive.
- `BUG_HANDLE_PROPGROUPS_IN_QUERY`: See radicale

### Radicale (used by Synology Contacts App)

- addressbook-query does not accept is-not-defined element in prop-filter
  - https://github.com/Kozea/Radicale/pull/1139
- `BUG_INVTEXTMATCH_SOMEMATCH`: see Sabre/DAV
  - https://github.com/Kozea/Radicale/issues/1140
- Radicale ignores the test attribute on a prop-filter and applies "allof" semantics (even though the default would be
  anyof)
  - https://github.com/Kozea/Radicale/issues/1143
- `BUG_HANDLE_PROPGROUPS_IN_QUERY`: Property groups (e.g. G1.EMAIL) are not properly handled in prop-filters. A
  prop-filter where the name attribute includes a group prefix must only match properties with that group prefix.

### iCloud

- Does not support allof test of multiple prop-filters (i.e. test="allof" at the filter level). It does support allof
  matching at the prop-filter level.
- `BUG_CASESENSITIVE_NAMES`: See Davical
