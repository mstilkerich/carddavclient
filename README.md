# CardDAV client library for PHP ("PHP-CardDavClient")
![CI Build](https://github.com/mstilkerich/carddavclient/workflows/CI%20Build/badge.svg)
[![codecov](https://codecov.io/gh/mstilkerich/carddavclient/branch/master/graph/badge.svg)](https://codecov.io/gh/mstilkerich/carddavclient/branch/master)
[![Type Coverage](https://shepherd.dev/github/mstilkerich/carddavclient/coverage.svg)](https://shepherd.dev/github/mstilkerich/carddavclient)
[![Psalm level](https://shepherd.dev/github/mstilkerich/carddavclient/level.svg?)](https://psalm.dev/)


This is a library for PHP applications to interact with addressbooks stored on CardDAV servers.

## Index

- [Features](#features)
- [Tested Servers](#tested-servers)
- [Installation instructions](#installation-instructions)
- [Quickstart](#quickstart)
- [API documentation](#api-documentation)

## Features

- CardDAV addressbook discovery as defined by [RFC 6764](https://tools.ietf.org/html/rfc6764) (using DNS SRV records
  and/or well-known URIs)
- Synchronization of the server-side addressbook and a local cache
  - Using efficient sync-collection ([RFC 6578](https://tools.ietf.org/html/rfc6578)) and addressbook-multiget
    ([RFC 6352](https://tools.ietf.org/html/rfc6352)) reports if supported by server
  - Falling back to synchronization via PROPFIND and comparison against the local cache state if the server does not
    support these reports
- Modification of addressbooks (adding/changing/deleting address objects)
- Uses [Guzzle](https://github.com/guzzle/guzzle) HTTP client library, including support for HTTP/2 and various
  authentication schemes, including OAuth2 bearer token
- Uses [Sabre/VObject](https://github.com/sabre-io/vobject) at the application-side interface to exchange VCards
- Uses any PSR-3 compliant logger object to record log messages and the HTTP traffic. A separate logger object is used
  for the HTTP traffic, which tends to be verbose and therefore logging for HTTP could be done to a separate location or
  disabled independent of the library's own log messages.

See the [feature matrix](doc/QUIRKS.md) for which services to my observations support which features; the file also
contains a list of the known issues I am aware of with the different servers.

## Tested Servers

Currently, this library has been tested to interoperate with:

* Nextcloud 18 and later (Basic Auth and GSSAPI/Kerberos 5)
* iCloud
* Google Contacts via CardDAV API
* Radicale 3 (also used by Synology as DSM CardDAV server)
* Owncloud 10
* Baïkal 0.7 (Digest Auth and GSSAPI/Kerberos 5)
* Davical 1.1.7

In theory, it should work with any CardDAV server. If it does not, please open an issue.

__Note: For using any authentication mechanism other than Basic, you need to have the php-curl extension installed with
support for the corresponding authentication mechanism.__

## Installation instructions

This library is intended to be used with [composer](https://getcomposer.org/) to install/update the library and its
dependencies. It is intended to be used with a PSR-4 compliant autoloader (as provided by composer).

To add the library as a dependency to your project via composer:

1. Download composer (skip if you already have composer): [Instructions](https://getcomposer.org/download/)

2. Add this library as a dependency to your project
```sh
php composer.phar require mstilkerich/carddavclient
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
   discover the user's addressbooks. It returns an array of *MStilkerich\CardDavClient\AddressbookCollection* objects,
   each representing an addressbook.

2. Recreate addressbooks in known locations, discovered earlier. This is possible by simply creating instances of
   *MStilkerich\CardDavClient\AddressbookCollection*.

3. Initially and periodically synchronize the server-side addressbook with a local cache: For this operation, the
   library provides a service class *MStilkerich\CardDavClient\Services\Sync*.
   This service performs synchronization given *MStilkerich\CardDavClient\AddressbookCollection* object and optionally a
   synchronization token returned by the previous sync operation. A synchronization token is a server-side
   identification of the state of the addressbook at a certain time. When a synchronization token is given, the server
   will be asked to only report the delta between the state identified by the synchronization token and the current
   state. This may not work for various reasons, the most common being that synchronization tokens are not kept
   indefinitly by the server. In such cases, a full synchronization will be performed. At the end of the sync, the
   service returns the synchronization token reflecting the synchronized state of the addressbook, if provided by the
   server.

4. Perform changes to the server-side addressbook such as creating new address objects. These operations are directly
   provided as methods of the *MStilkerich\CardDavClient\AddressbookCollection* class.

5. Search the server-side addressbook to retrieve cards matching certain filter criteria. This operation is provided via
   the *MStilkerich\CardDavClient\AddressbookCollection::query()* API.

There is a demo script [doc/quickstart.php](doc/quickstart.php) distributed with the library that shows how to perform
all the above operations.

### Sample Applications

For a simple demo application that makes use of this library, see [davshell](https://github.com/mstilkerich/davshell/).
It shows how to use the library for the discovery and synchronization of addressbooks.

As a more complex real-world application, you can also take a look at the
[Roundcube CardDAV](https://github.com/mstilkerich/rcmcarddav) plugin, which also uses this library for the interaction
with the CardDAV server.

### API documentation

An overview of the API is available [here](doc/README.md).

The API documentation for the latest released version can be found [here](https://mstilkerich.github.io/carddavclient/).
The public API of the library can be found via the `Public` package in the navigation sidebar.

Documentation for the API can be generated from the source code using [phpDocumentor](https://www.phpdoc.org/) by
running `make doc`.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
