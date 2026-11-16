#!/bin/sh

# Deploys a Stalwart server in a docker container, without going through the interactive setup wizard.
#
# Procedure (see https://stalw.art/docs/configuration/declarative-deployments/):
#   1. Start Stalwart in recovery mode with a config.json that only defines the data store. In recovery mode, only the
#      management API is available on plain HTTP port 8080.
#   2. Load the initial configuration (domain, test user) using stalwart-cli apply.
#   3. Recreate the container in normal mode on the same data volume. Stalwart creates its default listeners on this
#      start, including a plain HTTP listener on port 8080 that serves CardDAV.
#   4. Create an API key for the test user. Stalwart accepts API keys as bearer tokens, which allows to test bearer
#      authentication. The key is printed and, if an envFile is given, appended to it as STALWART_APIKEY=<key> (in a
#      github workflow, pass "$GITHUB_ENV" to make the key available to the following steps).
#
# Usage: deployStalwart.sh [stalwartImageTag] [stalwartCliImageTag] [envFile]
#        deployStalwart.sh cleanup
#
# The cleanup command stops and removes the container and deletes its data volume.

set -e

STALWART_TAG="${1:-v1}"
CLI_TAG="${2:-1}"
ENVFILE="$3"

CFGDIR="$(cd "$(dirname "$0")/../configs/stalwart" && pwd)"
CONTAINER=carddavclient-ci-stalwart
VOLUME=carddavclient-ci-stalwart-data
BASEURL="http://127.0.0.1:8080"
ADMINUSER=admin
ADMINPASS=r3covery-Adm1n-Passw0rd
# must match the test user created by plan.ndjson
TESTUSER=citest@example.org
TESTPASS=c1test-Passw0rd

runStalwart() {
    docker run -d --name "$CONTAINER" --init \
        -p 8080:8080 \
        -v "$CFGDIR/config.json:/etc/stalwart/config.json:ro" \
        -v "$VOLUME:/var/lib/stalwart" \
        "$@" \
        "stalwartlabs/stalwart:$STALWART_TAG"
}

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    docker volume rm "$VOLUME" >/dev/null 2>&1 || true
}

stalwartCli() {
    docker run --rm --network host \
        -v "$CFGDIR:/work:ro" -w /work \
        "stalwartlabs/cli:$CLI_TAG" \
        --url "$BASEURL" "$@"
}

waitForStalwart() {
    i=0
    until curl -sf -o /dev/null "$BASEURL/healthz/live"; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "Stalwart did not become ready in time" >&2
            docker logs "$CONTAINER" >&2
            exit 1
        fi
        sleep 1
    done
}

cleanup
if [ "$1" = "cleanup" ]; then
    echo "Removed container $CONTAINER and volume $VOLUME"
    exit 0
fi

docker volume create "$VOLUME"

echo "Starting Stalwart $STALWART_TAG in recovery mode"
runStalwart -e STALWART_RECOVERY_MODE=1 -e "STALWART_RECOVERY_ADMIN=$ADMINUSER:$ADMINPASS"
waitForStalwart

echo "Applying initial configuration"
stalwartCli --user "$ADMINUSER" --password "$ADMINPASS" apply --file plan.ndjson

echo "Restarting Stalwart in normal mode"
docker rm -f "$CONTAINER"
runStalwart
waitForStalwart

echo "Creating API key for $TESTUSER"
APIKEY_OUT=$(stalwartCli --user "$TESTUSER" --password "$TESTPASS" create ApiKey --field description='CI bearer auth')
APIKEY=$(printf '%s\n' "$APIKEY_OUT" | grep -o 'API_[A-Za-z0-9_-]*' | head -n 1)
if [ -z "$APIKEY" ]; then
    echo "Could not extract API key from stalwart-cli output:" >&2
    printf '%s\n' "$APIKEY_OUT" >&2
    exit 1
fi

if [ -n "$ENVFILE" ]; then
    echo "STALWART_APIKEY=$APIKEY" >> "$ENVFILE"
fi

echo "Stalwart is up at $BASEURL"
echo "STALWART_APIKEY=$APIKEY"
