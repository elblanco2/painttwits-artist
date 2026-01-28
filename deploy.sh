#!/bin/bash
#
# Artist Portfolio - Quick Deploy Script
#
# Usage:
#   ./deploy.sh                    # Deploy to current directory
#   ./deploy.sh /path/to/webroot   # Deploy to specified directory
#
# One-liner (download + deploy):
#   bash <(curl -sSL https://raw.githubusercontent.com/elblanco2/artist-portfolio/main/deploy.sh) /path/to/webroot
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}"
echo "============================================"
echo "  Artist Portfolio - Deploy"
echo "============================================"
echo -e "${NC}"

TARGET_DIR="${1:-.}"

# If we're not already inside the repo (no index.php), download it
if [ ! -f "index.php" ] && [ ! -f "$TARGET_DIR/index.php" ]; then
    echo -e "${YELLOW}Downloading latest release...${NC}"
    TEMP_DIR=$(mktemp -d)
    REPO_URL="https://github.com/elblanco2/artist-portfolio"

    if command -v git &>/dev/null; then
        git clone --depth 1 "$REPO_URL" "$TEMP_DIR/repo" 2>/dev/null && SOURCE_DIR="$TEMP_DIR/repo"
    fi

    if [ -z "$SOURCE_DIR" ]; then
        echo "git not available, trying zip download..."
        curl -sSL "$REPO_URL/archive/main.zip" -o "$TEMP_DIR/repo.zip"
        unzip -q "$TEMP_DIR/repo.zip" -d "$TEMP_DIR"
        SOURCE_DIR="$TEMP_DIR/artist-portfolio-main"
    fi

    if [ ! -f "$SOURCE_DIR/index.php" ]; then
        echo -e "${RED}Download failed. Please download manually from:${NC}"
        echo "  $REPO_URL"
        rm -rf "$TEMP_DIR"
        exit 1
    fi
else
    # Running from inside the repo
    SOURCE_DIR="."
fi

# Create target directory
if [ "$TARGET_DIR" != "." ] && [ ! -d "$TARGET_DIR" ]; then
    echo -e "${YELLOW}Creating directory: $TARGET_DIR${NC}"
    mkdir -p "$TARGET_DIR"
fi

# Copy files (exclude git/deploy artifacts)
echo -e "${YELLOW}Copying files...${NC}"
for item in "$SOURCE_DIR"/*; do
    base=$(basename "$item")
    case "$base" in
        .git|.github|deploy.sh|LICENSE|README.md|.gitignore) continue ;;
        *) cp -r "$item" "$TARGET_DIR/" ;;
    esac
done

# Copy hidden files that are needed
[ -f "$SOURCE_DIR/.htaccess" ] && cp "$SOURCE_DIR/.htaccess" "$TARGET_DIR/"

# Create directories
echo -e "${YELLOW}Setting up directories...${NC}"
mkdir -p "$TARGET_DIR/uploads/dzi"
mkdir -p "$TARGET_DIR/logs"
chmod 755 "$TARGET_DIR/uploads" "$TARGET_DIR/uploads/dzi" 2>/dev/null || true
chmod 755 "$TARGET_DIR/logs" 2>/dev/null || true

# Clean up
if [ -n "$TEMP_DIR" ] && [ -d "$TEMP_DIR" ]; then
    rm -rf "$TEMP_DIR"
fi

# Detect site URL
SITE_URL=""
if [ "$TARGET_DIR" = "." ]; then
    SETUP_PATH="/setup.php"
else
    # Try to determine URL from target path
    SETUP_PATH="$TARGET_DIR/setup.php"
fi

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  Deploy complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "Next step:"
echo ""
echo -e "  Open your browser and visit:"
echo -e "     ${YELLOW}https://YOUR-DOMAIN.COM/setup.php${NC}"
echo ""
echo -e "  The setup wizard will:"
echo -e "    - Ask for your name and email"
echo -e "    - Configure your gallery automatically"
echo -e "    - Optionally connect to the painttwits network"
echo ""
echo -e "  That's it! No manual config editing required."
echo ""
echo -e "Alternative (manual setup):"
echo -e "  Copy artist_config.sample.php to artist_config.php"
echo -e "  and edit with your details."
echo ""
echo -e "Docs: https://github.com/elblanco2/artist-portfolio"
echo ""
