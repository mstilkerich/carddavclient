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
- Uses any PSR-3 compliant logger object to record log messages and the HTTP traffic. A separate logger object is used
  for the HTTP traffic, which tends to be verbose and therefore logging for HTTP could be done to a separate location or
  disabled independent of the library's own log messages.

## Tested Servers

Currently, this library has been tested to interoperate with:

* Nextcloud 18 (Basic Auth and GSSAPI/Kerberos 5)
* iCloud
* Google Contacts via CardDAV API (using HTTP Basic Authentication, which is deprecated and will be disabled by Google in the future for OAuth2)
* Radicale 3
* Owncloud 10
* Baïkal 0.7 (Digest Auth and GSSAPI/Kerberos 5)

In theory, it should work with any CardDAV server.

__Note: For using any authentication mechanism other than Basic, you need to have the php-curl extension installed with support for the corresponding authentication mechanism.__

## Installation

This library is intended to be used with [composer](https://getcomposer.org/) to install/update the library and its dependencies.
It is intended to be used with a PSR-4 compliant autoloader (as provided by composer).

To add the library as a dependency to your project via composer:

1. Download composer (skip if you already have composer): [Instructions](https://getcomposer.org/download/)

2. Add this library as a dependency to your project
```sh
php composer.phar require mstilkerich/carddavclient:dev-master
```

3. To use the library in your application with composer, simply load composer's autoloader in your main php file:
```php
require 'vendor/autoload.php';
```
The autoloader will take care of loading this and other PSR-0/PSR-4 autoloader-compliant libraries.

## Documentation

### Quickstart

Generally, an application using this library will want to do some or all of the following things:

1. Discover addressbooks from the information provided by a user: For this operation, the library provides a service
   class *MStilkerich\CardDavClient\Services\Discovery*.
   The service takes the account credentials and a partial URI (at the minimum a domain name) and with that attempts to
   discover the user's addressbooks. It returns an array of *MStilkerich\CardDavClient\AddressbookCollection* objects, each
   representing an addressbook.

2. Recreate addressbooks in known locations, discovered earlier. This is possible by simply creating instances of
   *MStilkerich\CardDavClient\AddressbookCollection*.

3. Initially and periodically synchronize the server-side addressbook with a local cache: For this operation, the
   library provides a service class *MStilkerich\CardDavClient\Services\Sync*.
   This service performs synchronization given *MStilkerich\CardDavClient\AddressbookCollection* object and optionally a
   synchronization token returned by the previous sync operation. A synchronization token is a server-side identification
   of the state of the addressbook at a certain time. When a synchronization token is given, the server will be asked to
   only report the delta between the state identified by the synchronization token and the current state. This may not work
   for various reasons, the most common being that synchronization tokens are not kept indefinitly by the server. In such
   cases, a full synchronization will be performed. At the end of the sync, the service returns the synchronization token
   reflecting the synchronized state of the addressbook, if provided by the server.

3. Perform changes to the server-side addressbook such as creating new address objects. These operations are directly
   provided as methods of the *MStilkerich\CardDavClient\AddressbookCollection* class.

There is a demo script [doc/quickstart.php](doc/quickstart.php) distributed with the library that shows how to perform all the above
operations.

### Sample Applications

For a simple demo application that makes use of this library, see [davshell](https://github.com/mstilkerich/davshell/).
It shows how to use the library for the discovery and synchronization of addressbooks.

You can also take a look at my fork of the [Roundcube CardDAV](https://github.com/mstilkerich/rcmcarddav) plugin, which
also uses this library for the interaction with the CardDAV server.

### API documentation

Documentation for the API can be generated from the source code using [phpDocumentor](https://www.phpdoc.org/).

```sh
phpdoc -d src/ -t doc/api/ --title="CardDAV Client Library"
```
