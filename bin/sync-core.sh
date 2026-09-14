#!/usr/bin/env sh
# Refreshes the vendored copy of billto/shop-integration-core in lib/shop-integration-core.
#
# Usage: bin/sync-core.sh [path-to-core-checkout]
# Default source: a sibling checkout ../billto-shop-integration-core. The commit hash of the
# copied revision is written to lib/shop-integration-core/VERSION so drift is visible in review.
set -eu

SRC="${1:-../billto-shop-integration-core}"
DEST="lib/shop-integration-core"

if [ ! -d "$SRC/src" ]; then
    echo "Core checkout not found at $SRC (clone git@github.com:nozugroup/billto-shop-integration-core.git)" >&2
    exit 1
fi

rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/src" "$DEST/src"
cp "$SRC/LICENSE" "$DEST/LICENSE"
git -C "$SRC" rev-parse HEAD > "$DEST/VERSION"

echo "Synced core $(cat "$DEST/VERSION") into $DEST"
