# Specifics of CardDAV server implementations

## Google Contacts

Google's implementation of CardDAV is not compliant with the RFCs. The following
issues have so far been encountered:

### Creating / update of cards
- New cards should be inserted using a POST request (RFC 5995). The server will choose the URL and report it back via the Location header.
- New cards inserted using PUT will be created, but not at the requested URI. Thus the URI in this case would be unknown, and hence this method should not be used.
- Cards stored to the server are adapted by the server. The following has been observed so far:
  - When creating a card, the UID contained in the card is replaced by a server-assigned UID.
  - The PRODID property is discarded by the server
  - The server inserts a REV property

### Synchronization
- When requesting a sync-collection report with an empty sync-token, the server rejects it as a bad request.
  - Using an empty sync-token is explicitly allowed by RFC 6578 for the initial sync, and subsequent sync when the
    sync-token was invalidated
  - It works to fallback to using PROPFIND to determine all cards in the addressbook
- When attempting a sync-collection report with a sync-token previously returned by the server, Depth: 1 header
  is needed as otherwise the Google server apparently will only report on the collection itself, i. e. the
  sync result would look like there were no changes. According to RFC 6578, a Depth: 0 header is required and
  a bad request result should occur for any other value. [Issue](https://issuetracker.google.com/issues/160190530)
- Google reports cards as deleted that have not been deleted between the last sync and the current one,
  but probably before that. [Issue](https://issuetracker.google.com/issues/160192237)

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
