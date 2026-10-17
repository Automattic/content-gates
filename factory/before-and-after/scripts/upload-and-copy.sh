#!/bin/bash
# upload-and-copy.sh - Upload screenshots to the configured internal blob store
# Usage: ./upload-and-copy.sh <before.png> <after.png> [--markdown]
#
# Options:
#   --markdown    Print a PR markdown table instead of bare URLs
#
# Environment:
#   IMAGE_ADAPTER      Storage adapter (default and only shipped adapter: blob)
#   BLOB_UPLOAD_URL    Required by the blob adapter. Internal upload endpoint.
#
# Security:
#   Public hosts (0x0.st, GitHub Gist) were removed on purpose. Screenshots of
#   VIP surfaces can contain customer data. Only upload to an A8C-controlled
#   endpoint. Do not add a public adapter back.
#
# Examples:
#   BLOB_UPLOAD_URL=https://internal.example/upload ./upload-and-copy.sh before.png after.png --markdown

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADAPTERS_DIR="$SCRIPT_DIR/adapters"

IMAGE_ADAPTER="${IMAGE_ADAPTER:-blob}"

BEFORE_FILE="$1"
AFTER_FILE="$2"
shift 2 2>/dev/null || true

MARKDOWN_MODE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --markdown)
            MARKDOWN_MODE=true
            shift
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
done

if [[ -z "$BEFORE_FILE" || -z "$AFTER_FILE" ]]; then
    echo "Usage: $0 <before.png> <after.png> [--markdown]" >&2
    echo "" >&2
    echo "Requires BLOB_UPLOAD_URL to be set to an internal upload endpoint." >&2
    exit 1
fi

for f in "$BEFORE_FILE" "$AFTER_FILE"; do
    if [[ ! -f "$f" ]]; then
        echo "Error: File not found: $f" >&2
        exit 1
    fi
done

# Refuse anything that is not the blob adapter, even if someone sets IMAGE_ADAPTER.
if [[ "$IMAGE_ADAPTER" != "blob" ]]; then
    echo "Error: Adapter '$IMAGE_ADAPTER' is not permitted. Only 'blob' (internal endpoint) is allowed." >&2
    exit 1
fi

ADAPTER_SCRIPT="$ADAPTERS_DIR/$IMAGE_ADAPTER.sh"
if [[ ! -f "$ADAPTER_SCRIPT" ]]; then
    echo "Error: Adapter script missing: $ADAPTER_SCRIPT" >&2
    exit 1
fi

if [[ -z "$BLOB_UPLOAD_URL" ]]; then
    echo "Error: BLOB_UPLOAD_URL is not set. Refusing to upload." >&2
    echo "Alternative: attach the images through the PR comment box in an authenticated browser." >&2
    exit 1
fi

upload_file() {
    local file="$1"
    echo "Uploading: $(basename "$file") (via $IMAGE_ADAPTER)" >&2
    "$ADAPTER_SCRIPT" "$file"
}

BEFORE_URL=$(upload_file "$BEFORE_FILE")
AFTER_URL=$(upload_file "$AFTER_FILE")

if [[ "$MARKDOWN_MODE" == "true" ]]; then
    cat <<EOF
| Before | After |
|:------:|:-----:|
| ![Before]($BEFORE_URL) | ![After]($AFTER_URL) |
EOF
else
    echo "Before: $BEFORE_URL"
    echo "After: $AFTER_URL"
fi
