# Using SPNEGO / GSSAPI / Kerberos authentication

The carddavclient library supports authentication using the SPNEGO mechanism, which includes the possibility to
authenticate using a kerberos ticket without the need for a password.

## Prerequisites

- PHP curl extension with GSSAPI/SPNEGO support and support for the authentication mechanism to use (e.g. Kerberos 5)

## Usage

If the prerequisites are available and the client-side Kerberos configuration is properly available on the client
machine (e. g. `/etc/krb5.conf`), provide the kerberos principal name as username. The password is optional in this case
(empty string). If your server provides additional authentication options in case a ticket is not available, provide the
password for that mechanism.

### Delegated credentials in a web application

If the library is used by a web application (e.g. a webmail client) that itself authenticates its users with
SPNEGO, the webserver can delegate the user's Kerberos credentials to the application. With Apache's
mod\_auth\_gssapi, this is enabled with the `GssapiDelegCcacheDir` setting (see the configuration below). The webserver
then stores the user's tickets in a credential cache and passes the location of that cache to the application as the
request variable `KRB5CCNAME`. This allows the application to authenticate with the CardDAV server on behalf of the
user.

PHP provides this variable in `$_SERVER`, but not in the environment of the process, where the GSSAPI library used by
curl looks for the credential cache. Therefore, if `$_SERVER['KRB5CCNAME']` is set, the library copies it to the
process environment (using `putenv()`) before it performs SPNEGO authentication.

Note that the environment is shared by all threads of a process, and `putenv()` is not thread-safe. With a thread-safe
(ZTS) PHP build that handles requests of different users concurrently in one process (e.g. mod\_php with Apache's
worker or event MPM, or FrankenPHP), a request may use the credential cache of a different user's concurrent request.
Use delegated credentials only with a setup that handles one request at a time per process, such as PHP-FPM or
mod\_php with Apache's prefork MPM.

## Notes on server-side setup

It is quite common that CardDAV servers running inside a webserver let the webserver handle the authentication for
SPNEGO. Nextcloud does it this way, and also Sabre/DAV provides an authentication backend where the actual
authentication is carried out by Apache, allowing it to be used with any authentication mechanism that Apache supports.

Therefore, the following configuration snippet may be useful to setup Apache for use with SPNEGO / Kerberos 5. I use a
modified version of the Baïkal server to test the library with Kerberos authentication. This is the configuration I use
in Apache (it requires the Apache mod\_auth\_gssapi):

```
<VirtualHost *:80>
    ServerName baikal.domain.com

    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/baikalKrb/html

    RewriteEngine on
    RewriteRule /.well-known/carddav /dav.php [R=308,L]

    <Directory "/var/www/baikalKrb/html">
        Options None
        # If you install cloning git repository, you may need the following
        # Options +FollowSymlinks
        AllowOverride None

        AuthType GSSAPI
        AuthName "GSSAPI Logon"

        # The server needs access to its kerberos key in the keytab
        # It should contain a service principal like HTTP/baikal.domain.com@REALM
        GssapiCredStore keytab:/etc/apache2/apache.keytab

        # The following enables server-side support for credential delegation (not that it needs to
        # be enabled on the client-side as well, if desired. You need to specify a directory that the
        # webserver can write to
        # GSSAPI delegation enables the server to acquire tickets for additional backend services. For
        # a CardDAV server, you will not normally need this. For a different service like roundcube
        # webmail, this would enable the webmail client for example to authenticate on the user's behalf
        # with backend IMAP, SMTP or CardDAV servers.
        # GssapiDelegCcacheDir /var/run/apache2/krbclientcache

        # maps the kerberos principal to a local username based on the settings in /etc/krb5.conf
        # e. g. username@REALM -> username
        GssapiLocalName On

        # Restrict the mechanisms offered by SPNEGO to Kerberos 5
        GssapiAllowedMech krb5

        # Optional: The following allows to fallback to Basic authentication if no ticket is available.
        # In this case, the username and kerberos password are required and the webserver would use them
        # to acquire a ticket-granting ticket for the user from the KDC itself.
        #GssapiBasicAuth On
        #GssapiBasicAuthMech krb5

        Require valid-user
    </Directory>

    <IfModule mod_expires.c>
        ExpiresActive Off
    </IfModule>
</VirtualHost>
```

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
