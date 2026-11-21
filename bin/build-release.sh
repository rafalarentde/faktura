#!/usr/bin/env bash
# Builds a deployable release: production dependencies only, no tests/docs/dev files.
#
# Usage: bin/build-release.sh [output-dir]      (default: build/ inside the repository)
# Result: <output-dir>/faktura-release/   the tree to upload (public/ is the web root)
#         <output-dir>/faktura-release.tar.gz   the same tree as one archive
# Needs: bash, tar, php (8.3+), composer. Safe to run repeatedly; it recreates the output directory.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
DEST="$OUT/faktura-release"

# Refuse to wipe anything that could be the source tree or a system directory.
if [ -z "$OUT" ] || [ "$OUT" = "/" ] || [ "$OUT" = "$ROOT" ] || [ "$OUT" = "$HOME" ]; then
    echo "Refusing to use '$OUT' as output directory." >&2
    exit 2
fi

echo "==> Clean build directory: $DEST"
rm -rf "$DEST" "$OUT/faktura-release.tar.gz"
mkdir -p "$DEST"

echo "==> Copying application files"
# Everything the app needs at run time; dev-only and local state stays out.
tar -C "$ROOT" \
    --exclude='./.git' --exclude='./.gitignore' --exclude='./.gitattributes' --exclude='./.github' \
    --exclude='./.superpowers' --exclude='./.phpunit.cache' --exclude='./.phpunit.result.cache' \
    --exclude='./node_modules' --exclude='./vendor' --exclude='./build' \
    --exclude='./tests' --exclude='./docs' --exclude='./phpunit.xml' \
    --exclude='./config/config.php' \
    --exclude='./storage' \
    --exclude='./public/.git*' \
    -cf - . | tar -C "$DEST" -xf -

echo "==> Installing production dependencies"
# Run inside the build directory so the optimized autoloader is generated from the copied src/.
(cd "$DEST" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist)

# Composer may install from source (git clones) when no dist archive is available: drop VCS data,
# otherwise committing the release tree would add the packages as embedded repositories.
find "$DEST" -name '.git' -prune -exec rm -rf {} +

echo "==> Creating empty writable storage directories"
for d in pdf-cache tmp logos; do
    mkdir -p "$DEST/storage/$d"
    : > "$DEST/storage/$d/.gitkeep"
done

echo "==> Checking required release contents"
for p in public/index.php public/.htaccess public/assets resources/fonts lang templates migrations \
         config/config.example.php vendor/autoload.php bin/migrate.php bin/create-user.php bin/purge-audit.php \
         storage/pdf-cache/.gitkeep storage/tmp/.gitkeep storage/logos/.gitkeep; do
    [ -e "$DEST/$p" ] || { echo "Missing in release: $p" >&2; exit 1; }
done
ls "$DEST"/resources/fonts/*.ttf >/dev/null
[ -z "$(find "$DEST" -name '.git' -print -quit)" ] || { echo "VCS data left in release" >&2; exit 1; }
for p in tests docs .git .superpowers node_modules config/config.php; do
    [ ! -e "$DEST/$p" ] || { echo "Must not be in release: $p" >&2; exit 1; }
done

echo "==> php -l on every PHP file"
count=0
while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || { echo "Syntax check failed: $f" >&2; exit 1; }
    count=$((count + 1))
done < <(find "$DEST" -type f -name '*.php' -print0)
echo "    $count files OK"

echo "==> Writing archive"
tar -C "$OUT" -czf "$OUT/faktura-release.tar.gz" faktura-release
echo "Release ready:"
echo "  $DEST"
echo "  $OUT/faktura-release.tar.gz"
