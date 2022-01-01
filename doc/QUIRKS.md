# Known Features and Quirks of CardDAV server implementations / public CardDAV services

While CardDAV is a standardized protocol, not all services implement it properly. Be particularly warned when
interacting with Google Contacts. During the development of this library and particularly with the interoperability
tests that run against various server implementations and public services, I discovered various quirks with these
implementations. The library tries to work around them to the possible extent, but some cannot be worked around and will
inevitably be visible to the user.

For reference, this file documents everything I discovered so far to that end. I will also provide an overview on the
supported CardDAV server features.

## Feature Matrix

The following matrix lists which features are supported by which CardDAV service/server implementation, in the
latest released version at the time of this writing (see commit log for when this was updated). Following the
table, you find a short description of each feature. This list does not consider how well or buggy the feature
is implemented. For known server issues, see the Known issues section below.

- Sabre/DAV is the CardDAV implementation used by Nextcloud, Owncloud, Baïkal, and grammm (and possibly more).
- Radicale is the CardDAV implementation used by Synology DSM contacts app (again, there may be more I am not aware of).

Feature                     | iCloud | Google | Sabre | Davical | Radicale
----------------------------|--------|--------|-------|---------|---------
sync-collection             |   ✓    |   ✓    |   ✓   |   ✓     |   ✓
addressbook-multiget        |   ✓    |   ✓    |   ✓   |   ✓     |   ✓
Partial cards with multiget |   ✗    |   ✗    |   ✗   |   ✗     |   ✗
addressbook-query           |   ✓    |   ✓    |   ✓   |   ✓     |   ✓
Partial cards with query    |   ✗    |   ✓    |   ✓   |   ✓     |   ✗
Result limitation with query|   ✗    |   ✓    |   ✓   |   ✓     |   ✗
FEAT_FILTER_ALLOF           |   ✗    |   ✓    |   ✓   |   ✓     |   ✓
FEAT_PARAMFILTER            |   ✗    |   ✓    |   ✓   |   ✓     |   ✓
FEAT_ALLOF_SINGLEPROP       |   ✓    |   ✗    |   ✗   |   ✓     |   ✗


### Feature descriptions

#### sync-collection

The sync-collection REPORT (RFC 6578) allows efficient incremental synchronization with a server. With each
synchronization, the server provides a so-called sync-token that represents the state of the addressbook at the server
side at the time of synchronization. The client remembers this sync-token and provides it to the server with the next
sync request. The server will then only report the cards that have been added/changed/removed since the last
synchronization.

If a server does not support this report, carddavclient falls back to determining the changed cards itself by requesting
the getetag properties of all cards in the addressbook (getetag identifies the state of the card on the server, i.e. if
a card is modified its getetag property changes). This is less efficient than the sync-collection report.

The carddavclient library will automatically determine if the server supports sync-collection and transparently fall
back to the slower synchronization mechanism if it does not.  (See `Sync::synchronize()`).

#### addressbook-multiget

The addressbook-multiget report (RFC 6352) allows to fetch multiple cards from a server in a single request. It is thus
more efficient than the alternative of fetching each needed card in a separate request. It is used by
`Sync::synchronize()` automatically if supported by the server, otherwise it transparently falls back to fetching the
cards in separate requests.

##### Partial cards with multiget

A feature specified for the multiget report by RFC 6352 is the partial retrieval of cards. It allows the client to
request only specific VCard properties (e.g., `FN`, `EMAIL`) from the server. This specifically can greatly speed up the
retrieval of cards by omitting large properties such as `PHOTO` in case they are not required. This feature is exposed
by carddavclient as the `$requestedVCardProps` parameter of `Sync::synchronize()`. Unfortunately, to date I have not
found a server actually supporting this feature. For Sabre/DAV, see [PR](https://github.com/sabre-io/dav/pull/1310).

#### addressbook-query

The addressbook-query report (RFC 6352) allows to query cards from an addressbook that match certain filter criteria.
This is an alternative to synchronization for efficiently using a CardDAV addressbook without keeping a local copy. This
functionality is exposed by carddavclient via the `AddressbookCollection::query()` interface.

The filter for an addressbook-query report consists of one or more property filters (`prop-filter`), that match on a
VCard property (e.g., `FN`, `EMAIL`). If multiple property filters are given, one can choose whether all of the filters
need to match (`allof` / `AND`) or are single match is sufficient (`anyof` / `OR`) for a card to be returned.

Each property filter can match on whether a property is defined with an arbitrary value, is not defined, has a value
matching or not matching a text filter, or matching a parameter filter (`param-filter`). It is possible to combine a
textual match and a parameter filter, with the same `allof` vs. `anyof` matching behavior as for multiple property
filters.

A parameter filter can match on whether a defined property has the given parameter (e.g. `TYPE`) defined with an
arbitrary value, has the parameter not defined, or has the parameter defined with a value that matches (or does not
match) a text filter.

##### Partial cards with query

Allows partial retrieval of cards in the same way as for [multiget](#partial-cards-with-multiget). With
addressbook-query, however, this feature is supported by more servers and also of increased importance as the use cases
of addressbook-query tend to incur more interaction with the CardDAV server, and thus avoidance of unneeded traffic
weighs-higher. This feature is available through carddavclient as the `$requestedVCardProps` parameter of the
`AddressbookCollection::query()` API.

##### Result limitation with query

Allows to limit the results returned by addressbook query to a maximum number of cards. This helps in use cases such as
autocompletion where it makes no sense to present a large amount of records to the user. This feature is available
through carddavclient as the `$limit` parameter of the `AddressbookCollection::query()` API.

##### Allof/AND filtering at the filter level with query (`FEAT_FILTER_ALLOF`)

Allows to use AND filtering for multiple property filters, i.e. all the filters need to match a card to be returned in
the result of an addressbook-query. This feature corresponds to the `matchAll=true` setting available in the elaborate
form of the `$conditions` parameter to the `AddressbookCollection::query()` API.

Normally als CardDAV servers should support this, but iCloud does not. Instead, it appears to apply `anyof` semantics,
resulting in extra cards in the result that do not match the filter.

##### Support for parameter filter with query (`FEAT_PARAMFILTER`)

Allows to specify parameter filters inside a property filter. These can match a property only when it has a specific
parameter (not) defined, or defined (not) matching a specific value.

iCloud apparently does not support param-filter. It simply ignores the filter and returns all cards that match the
remaining conditions, i.e. at least that the property that contains the param-filter is defined is used as filter.

##### Same value of a multi-value property needs to match all filters of a prop-filter (`FEAT_ALLOF_SINGLEPROP`)

I am actually not sure whether this really is a feature or differing behavior can be considered a bug, because RFC 6352
is not entirely clear on the behavior. It is best explained by an example: Say we have a vcard with a multiple values
for a property, such as `EMAIL` (for brevity I omit the other parts of the vcard):

```
EMAIL:doe@big.corp
EMAIL:johndoe@example.com
```

If we use a property filter containing several text match sub-filters, it is not clear whether the same value of the
property needs to match all the sub-filters, or whether each of the sub-filters must be matched by any property. For
example, in the complex filter syntax of `AddressbookCollection::query()`: `['/doe/^', '/.com/$', 'matchAll' => true]]`.

From my perspective, this filter searches for cards that have an `EMAIL` starting with `doe` and ending in `.com`. So I
would not want the above card to be returned. However, the first email address matches the first sub-filter (starts with
`doe`), but not the second sub-filter (ends with `.com`). The second email address matches the second sub-filter, but
not the first. Now for servers supporting this feature, a single value of the multi-value property (here a single email
address) must match all filters, and consequently the above example card will not match the example filter. Some servers
that are marked to not have this feature work differently though and will return the card.

## Known issues and quirks of CardDAV server implementations

BUG_REJ_EMPTY_SYNCTOKEN | Empty synctoken not accepted for initial sync-collection report
--------|----------------------------------------------------------
Affected servers / services | [Google Contacts](https://issuetracker.google.com/issues/160190530)
Description | For the initial sync, the server must accept an empty sync-token and consequently report all address objects within the addressbook collection in its result, plus a sync-token to be used for follow-up syncs. The server rejects a sync-collection report request carrying an empty sync-token with `400 Bad Request`.
Affected operations | `Sync::synchronize()` when called with an empty `$prevSyncToken` parameter.
User-visibile impact and possible workaround | Carddavclient will transparently fall back to a slower synchronization method based on `PROPFIND`. Carddavclient will ask the server for a synctoken that can be used for future incremental syncs using the sync-collection report. A log message with loglevel *error* will be logged.

BUG_ETAGPRECOND_NOTCHECKED | ETag precondition ignored when storing a vcard
--------|----------------------------------------------------------
Affected servers / services | Google Contacts
Description | When updating a vcard on the server, one should make the operation dependent on the precondition that the ETag of the server-side card still is the same as for the card that was locally used as base date for the update. If another client changed the card at the server in the meantime, this makes the update fail and avoids overwriting the other client's changes. Google ignores the precondition and performs the update even in case of ETag mismatch.
Affected operations | `AddressbookCollection::updateCard()` when called with a non-empty `$etag` parameter.
User-visibile impact and possible workaround | Changes of another client could be overwritten. No workaround available.


[]()    | Depth: 0 header rejected for sync-collection report
--------|----------------------------------------------------------
Affected servers / services | [Google Contacts](https://issuetracker.google.com/issues/160190530)
Description | According to RFC 6578, a `Depth: 0` header MUST be used with a sync-collection REPORT request, otherwise the server must reject it as `400 Bad Request`. The Google Contacts API seems to interpret this header for the depth of the request (which is per RFC 6578 given in the `DAV:sync-level` element). As a consequence, the response from Google Contacts will always appear as if there had been no changes. Using a `Depth: 1` header returns the expected result, but a CardDAV client cannot use this as this must be expected to fail with RFC compliant server implementations.
Affected operations | `Sync::synchronize()`
User-visibile impact and possible workaround | Carddavclient transparently works around the problem by specifically sending a `Depth: 1` header for addressbooks under the `www.googleapis.com` domain. For all other domains, the library will send a `Depth: 0` header in compliance with RFC 6578.


[]() | Internal server error on REPORTs with digest authentication
--------|----------------------------------------------------------
Affected servers / services | [Sabre/DAV](https://github.com/sabre-io/dav/issues/932)
Description | Background: When using DIGEST authentication, it is required to first send a request to the server to determine the parameters for the DIGEST authentication. This request is supposed to fail with 401 and the client can determine the parameters from the WWW-Authenticate header and try again with the proper Authentication header. Curl optimizes the first request by omitting the request body as it expects the request to fail anyway.
[]() | Now sabre/dav has a feature that allows to reply to certain REPORT requests without the need for authentication. This is specifically useful for Caldav, which may want to make available certain information from a calendar to anonymous users (e.g. free/busy time). Therefore, the authentication is done at a later time than the first attempt to evaluate the REPORT. A REPORT request requires a body, and thus sabre/dav will bail out with an internal server error instead of a 401, normally causing the client library to fail. The problem specifically only occurs for REPORT requests, for other requests such as PROPFIND the problem is not triggered in sabre and an expected 401 response is returned.
[]() | As a sidenote, nextcloud is not affected even though it uses sabre/dav, because the feature causing the server errors can be disabled and is in nextcloud. But there are other servers (Baïkal) using sabre/dav that are affected.
Affected operations | `Sync::synchronize()`, `AddressbookCollection::query()`
User-visibile impact and possible workaround | As a workaround, it is possible to ask curl to do negotiation of the authentication scheme to use, but providing the authentication scheme CURLAUTH_ANY. With this, curl will not assume that the initial request might fail (as not authentication may be needed), and thus the initial request will include the request body. The downside of this is that even when we know the authentication scheme supported by a server (e.g. basic), this setting will cause twice the number of requests being sent to the server.
[]() | Because it doesn't seem that this issue will get fixed, and the widespread usage of sabre/dav, I decided to include this workaround in the carddavclient library that specifically detects the situation and applies the above workaround without affecting the efficiency of communication when talking to other servers.


[]()    | UID of created VCard reassigned by server
--------|----------------------------------------------------------
Affected servers / services | Google Contacts
Description | This is not a bug, but something the user should be aware of. Every VCard stored to a CardDAV server requires a `UID` property. When a new card is stored to Google Contacts, the server will replace the `UID` that is stored in the card with one assigned by the server.
Affected operations | `AddressbookCollection::createCard()`
User-visibile impact and possible workaround | The user must not assume that a newly created card will retain the UID assigned by the client application. If the UID is stored locally, for example to map locally cached cards against those retrieved from the server, the user should download the card after creation and use the UID property from the retrieved vcard.


[]()    | Stored VCard modified by server
--------|----------------------------------------------------------
Affected servers / services | Google Contacts
Description | This is probably within what the server is allowed to do, but something the user should be aware of. Google Contacts will modify VCards stored to the server, probably "lost in translation" to an internal data model and back. Currently, so following have been observed:
 []() | - The `TYPE` parameter that can be used with properties such as `EMAIL` is constrained to a single value. Values not known to Google Contacts are discarded. This includes values explicitly allowed by RFC2426, e.g. *internet* as an `EMAIL` type.
 []() | - For `IMPP` properties, the protocol scheme and `X-SERVICE-TYPE` parameter spelling (e.g. *jabber* becomes *Jabber*) is adapted by the server.
Affected operations | `AddressbookCollection::createCard()`, `AddressbookCollection::updateCard()`
User-visibile impact and possible workaround | The user should not expect a VCard stored to the server to be identical with the VCard read back from the server. To preserve custom labels on the server, the `X-ABLabel` extension can be used, however, support by CardDAV client applications is not as good as for the `TYPE` parameter.


### Issues/quirks specific to addressbook query

Because there are so many issues concerning the handling of the addressbook-query report, these are grouped in this section. Most problems concern the use of negated text matches or parameter filters, and thus can be avoided by not using such filters.

BUG_CASESENSITIVE_NAMES | Names treated case sensitive in addressbook query
--------|----------------------------------------------------------
Affected servers / services | Google Contacts, [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/20), iCloud
Description | In addressbook-query, the server treats property and group names case sensitive, i.e. when searching for a property `email` it will not match an `EMAIL` property. However, according to RFC 6350, names of properties and groups are case insensitive. For Google, the issue additionally applies to parameter names.
Affected operations | `AddressbookCollection::query()`
User-visibile impact and possible workaround | The `query()` result may lack cards that would have matched the filter. Use uppercase spelling in your conditions for maximunm interoperability, as this is the recommended spelling and some servers automatically convert names to uppercase when a card is stored. This will not completely mitigate the issue though.


[]() | Query on multi-value properties may wrongly filter out cards
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/issues/20)
Description | A query filtering on a property of that multiple instances may exist (e.g. `EMAIL`), the server may filter out cards that have one instance not matching the filter conditions, even though there is another instance that matches.
Affected operations | `AddressbookCollection::query()`
User-visibile impact and possible workaround | The `query()` result may lack cards that match the filter.


[]() | Server does not support multiple prop-filter subfilters
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/19)
Description | The server does not support multiple prop-filter subfilters, it ignores all but the first one.
Affected operations | `AddressbookCollection::query()` when using multiple conditions inside a prop-filter.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results or lack expected results.


BUG_HANDLE_PROPGROUPS_IN_QUERY | Property groups are not properly handled
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/20), Radicale
Description | Property groups (e.g. `G1.EMAIL`) are not properly handled in prop-filters. A prop-filter where the name attribute includes a group prefix must only match properties with that group prefix.
Affected operations | `AddressbookCollection::query()` when using property names with group prefix
User-visibile impact and possible workaround | The `query()` result may contain unexpected results.

[]() | Filtering for non-defined properties results in bad request
--------|----------------------------------------------------------
Affected servers / services | [Radicale < 3.1.0](https://github.com/Kozea/Radicale/pull/1139)
Description | The server rejects a filter for non-defined properties (e.g. `'EMAIL' => null` as bad request.
Affected operations | `AddressbookCollection::query()` when using property filters for non-defined properties
User-visibile impact and possible workaround | The query operation fails with a bad request error


[]() | Property filter with multiple conditions always uses "allof"/AND semantics
--------|----------------------------------------------------------
Affected servers / services | [Radicale](https://github.com/Kozea/Radicale/issues/1143)
Description | The server ignores the test attribute on a prop-filter and applies "allof" semantics (even though the default would be anyof)
Affected operations | `AddressbookCollection::query()` when using property filters with multiple filter conditions and "anyof" semantics
User-visibile impact and possible workaround | The `query()` result may lack expected results.


#### Related to the use of negated text matches

BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS | Negated text matches yield results the lack the matched property
--------|----------------------------------------------------------
Affected servers / services | [Google Contacts](https://issuetracker.google.com/issues/178251714), [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/15)
Description | When issuing an addressbook-query with a prop-filter containing a negated text-match, the server also returns cards that lack the asked for property. Example: If you filter for an `EMAIL` with a `!/foo/` text filter, the server will return cards that do not have an `EMAIL` property at all.
Affected operations | `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a property.
User-visibile impact and possible workaround | The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.


BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS | Negated text matches on parameter yield results the lack the matched property/parameter
--------|----------------------------------------------------------
Affected servers / services | [Google Contacts](https://issuetracker.google.com/issues/178251714), [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/18)
Description | When issuing an addressbook-query with a param-filter containing a negated text-match, the server also returns cards that lack the asked for property or parameter. Example: If you filter for an `EMAIL;TYPE` with a `!/foo/` text filter, the server will return cards that a) do not have an `EMAIL` property at all, or b) have an `EMAIL` property that lacks the `TYPE` parameter.
Affected operations | `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a parameter.
User-visibile impact and possible workaround | The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.


BUG_INVTEXTMATCH_SOMEMATCH | Negated text match misses cards that also have property instances matching the non-negated filter
--------|----------------------------------------------------------
Affected servers / services | Google Contacts, [Sabre/DAV](https://github.com/sabre-io/dav/pull/1322), [Radicale](https://github.com/Kozea/Radicale/issues/1140)
Description | A negated text-match on a property yields wrong results if there is a property instance matching the text-match and another that does not. This is because the server will simply invert the result of checking all properties, when it should check if there is any property NOT matching the text-filter (!= NO property matching the text filter).
Affected operations | `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a property.
User-visibile impact and possible workaround | The `query()` result may lack cards that match the filter when using negated text matches on properties.

#### Related to the use of parameter filters

[]() | Filtering for not-defined parameters yields cards that lack the enclosing property
--------|----------------------------------------------------------
Affected servers / services | [Google Contacts](https://issuetracker.google.com/issues/178243204)
Description | When filtering for a not-defined parameter (e.g. `'EMAIL' => '['TYPE' => null]`), the server returns cards that do not have the enclosing property. However, in such cases, the param-filter should have no relevance. So in the example, the server would return cards that do not have an `EMAIL` property.
Affected operations | `AddressbookCollection::query()` when using filters for not-defined parameters.
User-visibile impact and possible workaround | The `query()` result may contain results that do not actually match the conditions specified by the user. As a workaround, the user could post-filter the received cards. Carddavclient does not currently perform any filtering on the query results itself but forwards what the server returned.


BUG_MULTIPARAM_NOINDIVIDUAL_MATCH | Text matches against multi-value parameters are not matched against the individual values
--------|----------------------------------------------------------
Affected servers / services | Google Contacts, Sabre/DAV, [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/19)
Description | A param-filter text-match for a parameter with multiple values (e.g. `TYPE=HOME,WORK`) will not match against the individual parameter values, but as the parameter string as a whole. For example an equals text-match for `/HOME/=` would not match the example given before.
Affected operations | `AddressbookCollection::query()` when querying parameters that may have multiple values.
User-visibile impact and possible workaround | The `query()` result may lack cards that would have matched the filter. By using contains text matches (e.g. `/HOME/`), the effect can be avoided (of course the results might differ in that case).


BUG_PARAMFILTER_ON_NONEXISTENT_PARAM | Internal server error on param-filter when property lacks the parameter
--------|----------------------------------------------------------
Affected servers / services | [Sabre/DAV](https://github.com/sabre-io/dav/pull/1322)
Description | When filtering on a parameter, an internal server error will occur if the server encounters a property of the asked for type that does not have the parameter. Examples: Filtering for `'EMAIL' => ['TYPE', '/foo']` raises a server error when there is a VCard with an `EMAIL` property that does not have a `TYPE` parameter.
Affected operations | `AddressbookCollection::query()` when using parameter filters
User-visibile impact and possible workaround | The request will fail with an internal server error.


[]() | Negated text-match on parameter yields wrong results
--------|----------------------------------------------------------
Affected servers / services | [Sabre/DAV](https://github.com/sabre-io/dav/pull/1322)
Description | A negated text-match on a parameter yields wrong results if there is a property instance with a parameter matching the text-match and another that does not. This is because the server will simply invert the result of checking all properties.
Affected operations | `AddressbookCollection::query()` when using negated text matches inside the `$conditions` for a parameter filter.
User-visibile impact and possible workaround | The `query()` result may lack cards that match the filter when using negated text matches on parameters.


[]() | Wrong results on parameter filter (matched against property value)
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/issues/21)
Description | The server matches parameter filters against the value of the enclosing property, not the parameter.
Affected operations | `AddressbookCollection::query()` when using parameter filters with text matches.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results or lack expected results.


[]() | Parameter text match is case sensitive and checks for substring match only
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/issues/21)
Description | The server ignores the collation and match-type provided for a text-match inside a parameter filter, carrying out case-sensitive matching with a match-type of contains (i.e. parameter value contains search string).
Affected operations | `AddressbookCollection::query()` when using parameter filters with text matches.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results or lack expected results.


BUG_PARAMNOTDEF_SOMEMATCH | Wrong result when matching for non-defined parameters
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/16)
Description | addressbook-query with a param-filter for a not-defined parameter yields wrong results for cards where the parameter is present for some properties, but not all (it must not be present at all for a match)
Affected operations | `AddressbookCollection::query()` when using parameter filters matching for non-defined parameters.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results.

BUG_PARAMDEF | Wrong result when matching for existence of parameter
--------|----------------------------------------------------------
Affected servers / services | [Davical](https://gitlab.com/davical-project/awl/-/merge_requests/20)
Description | addressbook-query with a param-filter for a defined parameter (i.e. no subfilter) matches cards that have the property, even if they lack the parameter.
Affected operations | `AddressbookCollection::query()` when using parameter filters matching for defined parameters.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results.

BUG_PARAMCOMMAVALUE | Comma not properly handled in parameter values
--------|----------------------------------------------------------
Affected servers / services | Davical
Description | addressbook-query with a param-filter for a parameter value that includes a comma may returned wrong results. This is because Davical splits the parameter value on the comma without considering quoting, and treats the parts left and right of the comma as multiple values of the parameter.
Affected operations | `AddressbookCollection::query()` when using parameter filters matching for parameter values when one value includes a comma.
User-visibile impact and possible workaround | The `query()` result may contain unexpected results, or lack expected results.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix: -->
