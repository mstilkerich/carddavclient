<?php

///////////////////////////////////////
//   CONFIGURATION OF ACCOUNT DATA   //
///////////////////////////////////////

// To run, configure the account data in this block and remove / comment out
// the following line with the exit statement
// DO NOT SERVE THIS SCRIPT VIA WEBSERVER
echo "Please configure first - exiting\n"; exit(1);

const DISCOVERY_URI = "example.com";
const USERNAME = "myUserName@example.com";

// Note: for providers using 2-factor authentication (common today, e.g. Apple iCloud,
// Google, Nextcloud if 2FA enabled by user), you need to provide an application-specific
// password here, not your account password. You can typically create such
// application-specific passwords in the account settings of your provider.
const PASSWORD = "theSecretPassword";

///////////////////////////////////////
// END CONFIGURATION OF ACCOUNT DATA //
///////////////////////////////////////

require 'vendor/autoload.php';

use MStilkerich\CardDavClient\{Account, AddressbookCollection, Config};
use MStilkerich\CardDavClient\Services\{Discovery, Sync, SyncHandler};

use Psr\Log\{AbstractLogger, NullLogger, LogLevel};
use Sabre\VObject\Component\VCard;

// This is just a sample logger for demo purposes. You can use any PSR-3 compliant logger,
// there are many implementations available (e.g. monolog)
class StdoutLogger extends AbstractLogger
{
    public function log($level, $message, array $context = array()): void
    {
        if ($level !== LogLevel::DEBUG) {
            $ctx = empty($context) ? "" : json_encode($context);
            echo $message . $ctx . "\n";
        }
    }
}

class EchoSyncHandler implements SyncHandler
{
    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        if (isset($card)) {
            $fn = $card->FN ?? "<no name>";
            echo "   +++ Changed or new card $uri (ETag $etag): $fn\n";
        } else {
            echo "   +++ Changed or new card $uri (ETag $etag): Error: failed to retrieve/parse card's address data\n";
        }
    }

    public function addressObjectDeleted(string $uri): void
    {
        echo "   --- Deleted Card $uri\n";
    }

    public function getExistingVCardETags(): array
    {
        return [];
    }

    public function finalizeSync(): void
    {
    }
}

$log = new StdoutLogger();
$httplog = new NullLogger(); // parameter could simply be omitted for the same effect

// Initialize the library. Currently, only objects for logging need to be provided, which are two optional logger
// objects implementing the PSR-3 logger interface. The first object logs the log messages of the library itself, the
// second can be used to log the HTTP traffic. If no logger is given, no log output will be created. For that, simply
// call Config::init() and the library will internally use NullLogger objects.
Config::init($log, $httplog);

// Now create an Account object that contains credentials and discovery information
$account = new Account(DISCOVERY_URI, ["username" => USERNAME, "password" => PASSWORD]);

// Discover the addressbooks for that account
try {
    $log->notice("Attempting discovery of addressbooks");

    $discover = new Discovery();
    $abooks = $discover->discoverAddressbooks($account);
} catch (\Exception $e) {
    $log->error("!!! Error during addressbook discovery: " . $e->getMessage());
    exit(1);
}

$log->notice(">>> " . count($abooks) . " addressbooks discovered");
foreach ($abooks as $abook) {
    $log->info(">>> - $abook");
}

if (count($abooks) <= 0) {
    $log->warning("Cannot proceed because no addressbooks were found - exiting");
    exit(0);
}
//////////////////////////////////////////////////////////
// THE FOLLOWING SHOWS HOW TO PERFORM A SYNCHRONIZATION //
//////////////////////////////////////////////////////////
$abook = $abooks[0];
$synchandler = new EchoSyncHandler();
$syncmgr = new Sync();

// initial sync - we don't have a sync-token yet
$log->notice("Performing initial sync");
$lastSyncToken = $syncmgr->synchronize($abook, $synchandler, [ "FN" ], "");
$log->notice(">>> Initial Sync completed, new sync token is $lastSyncToken");

// every subsequent sync would be passed the sync-token returned by the previous sync
// there most certainly won't be any changes to the preceding one at this point and we
// can expect the same sync-token be returned again
$log->notice("Performing followup sync");
$lastSyncToken = $syncmgr->synchronize($abook, $synchandler, [ "FN" ], $lastSyncToken);
$log->notice(">>> Re-Sync completed, new sync token is $lastSyncToken");

//////////////////////////////////////////////////////////////
// THE FOLLOWING SHOWS HOW TO PERFORM CHANGES ON THE SERVER //
//////////////////////////////////////////////////////////////


// First, we want to insert a new card, so we create a fresh one
// See https://sabre.io/vobject/vcard/ on how to work with Sabre VCards
// CardDAV VCards require a UID property, which the carddavclient library will
// generate and insert automatically upon storing a new card lacking this property

try {
    $vcard =  new VCard([
        'FN'  => 'John Doe',
        'N'   => ['Doe', 'John', '', '', ''],
    ]);


    $log->notice("Attempting to create a new card on the server");
    [ 'uri' => $cardUri, 'etag' => $cardETag ] = $abook->createCard($vcard);
    $log->notice(">>> New card created at $cardUri with ETag $cardETag");

    // now a sync should return that card as well - lets see!
    $log->notice("Performing followup sync");
    $lastSyncToken = $syncmgr->synchronize($abook, $synchandler, [ "FN" ], $lastSyncToken);
    $log->notice(">>> Re-Sync completed, new sync token is $lastSyncToken");

    // add an EMAIL address to the card and update the card on the server
    $vcard->add(
        'EMAIL',
        'johndoe@example.org',
        [
            'type' => ['home'],
            'pref' => 1,
        ]
    );

    // we pass the ETag of our local copy of the card to updateCard. This
    // will make the update operation fail if the card has changed on the
    // server since we fetched our local copy
    $log->notice("Attempting to update the previously created card at $cardUri");
    $cardETag = $abook->updateCard($cardUri, $vcard, $cardETag);
    $log->notice(">>> Card updated, new ETag: $cardETag");

    // again, a sync should report that the card was updated
    $log->notice("Performing followup sync");
    $lastSyncToken = $syncmgr->synchronize($abook, $synchandler, [ "FN" ], $lastSyncToken);
    $log->notice(">>> Re-Sync completed, new sync token is $lastSyncToken");

    // finally, delete the card
    $log->notice("Deleting card at $cardUri");
    $abook->deleteCard($cardUri);
    // now, the sync should report the card was deleted
    $log->notice("Performing followup sync");
    $lastSyncToken = $syncmgr->synchronize($abook, $synchandler, [ "FN" ], $lastSyncToken);
    $log->notice(">>> Re-Sync completed, new sync token is $lastSyncToken");

    $log->notice("All done, good bye");
} catch (\Exception $e) {
    $log->error("Error while making changes to the addressbook: " . $e->getMessage());
    $log->error("Manual cleanup (deletion of the John Doe card) may be needed");

    // do one final attempt to delete the card
    try {
        if (isset($cardUri)) {
            $abook->deleteCard($cardUri);
        }
    } catch (\Exception $e) {
    }

    exit(1);
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
