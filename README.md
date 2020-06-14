# CardDAV client library for PHP ("PHP-CardDavClient")

This is a library for PHP applications to interact with addressbooks stored on CardDAV servers.

## Features

- CardDAV addressbook discovery as defined by [RFC 6764](https://tools.ietf.org/html/rfc6764) (using DNS SRV records and/or well-known URIs)
- Synchronization of the server-side addressbook and a local cache
  - Using efficient sync-collection ([RFC 6578](https://tools.ietf.org/html/rfc6578)) and addressbook-multiget ([RFC 6352](https://tools.ietf.org/html/rfc6352)) reports if supported by server
  - Falling back to synchronization via PROPFIND and comparison against the local cache state if the server does not support these reports
- Modification of addressbooks (adding/changing/deleting address objects)  
- Uses [Guzzle](https://github.com/guzzle/guzzle) HTTP client library, including support for HTTP/2 and various authentication schemes
  - OAuth/OAuth2 is *not* supported at the moment
- Uses [Sabre/VObject](https://github.com/sabre-io/vobject) at the application-side interface to exchange VCards

## Tested Servers

Currently, this library has been tested to interoperate with:

* Nextcloud 18
* iCloud
* Google Contacts via CardDAV API (using HTTP Basic Authentication, which is deprecated and will be disabled by Google in the future for OAuth2)

## Installation

This library is intended to be used with [composer](https://getcomposer.org/) to install/update the library and its dependencies.
It is intended to be used with a PSR-4 compliant autoloader (as provided by composer).

To use the library in your application with composer, simply load composer's autoloader:
```php
require 'vendor/autoload.php';
```

## Documentation

Documentation is currently only available inside the php files.

### Sample Application

For a simple demo application that makes use of this library, see [davshell](https://github.com/mstilkerich/davshell/). It shows how to use the library for the discovery and synchronization of addressbooks and is currently the best available quick start information.

You can also take a look at my fork of the [Roundcube CardDAV](https://github.com/mstilkerich/rcmcarddav) plugin, which also uses this library for the interaction with the CardDAV server.
