#!/bin/bash
#
# Paint Twits Artist Portfolio - Quick Deploy Script
#
# Usage:
#   ./deploy.sh                    # Deploy to current directory
#   ./deploy.sh /path/to/webroot   # Deploy to specified directory
#   curl -sSL https://painttwits.com/deploy.sh | bash -s -- /path/to/webroot
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
echo "╔════════════════════════════════════════════════════════════╗"
echo "║         Paint Twits Artist Portfolio Deployer              ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Determine target directory
TARGET_DIR="${1:-.}"

# If downloading fresh, clone to temp first
if [ ! -f "index.php" ]; then
    echo -e "${YELLOW}Downloading latest template...${NC}"
    TEMP_DIR=$(mktemp -d)
    git clone --depth 1 https://github.com/yourusername/painttwits.git "$TEMP_DIR" 2>/dev/null || {
        echo -e "${RED}Failed to clone repository. Trying direct download...${NC}"
        curl -sSL https://github.com/yourusername/painttwits/archive/main.zip -o "$TEMP_DIR/painttwits.zip"
        unzip -q "$TEMP_DIR/painttwits.zip" -d "$TEMP_DIR"
        mv "$TEMP_DIR"/painttwits-main/* "$TEMP_DIR/" 2>/dev/null || true
    }
    SOURCE_DIR="$TEMP_DIR/templates/artist-portfolio"
else
    SOURCE_DIR="."
fi

# Create target directory if needed
if [ ! -d "$TARGET_DIR" ]; then
    echo -e "${YELLOW}Creating directory: $TARGET_DIR${NC}"
    mkdir -p "$TARGET_DIR"
fi

# Copy files
echo -e "${YELLOW}Copying files to $TARGET_DIR...${NC}"
cp -r "$SOURCE_DIR"/* "$TARGET_DIR/" 2>/dev/null || {
    echo -e "${RED}Failed to copy files. Check permissions.${NC}"
    exit 1
}

# Create uploads directory with proper permissions
echo -e "${YELLOW}Setting up uploads directory...${NC}"
mkdir -p "$TARGET_DIR/uploads/dzi"
chmod 755 "$TARGET_DIR/uploads" "$TARGET_DIR/uploads/dzi" 2>/dev/null || true

# Create config from sample
if [ ! -f "$TARGET_DIR/artist_config.php" ]; then
    if [ -f "$TARGET_DIR/artist_config.sample.php" ]; then
        cp "$TARGET_DIR/artist_config.sample.php" "$TARGET_DIR/artist_config.php"
        echo -e "${GREEN}Created artist_config.php from sample${NC}"
    fi
fi

# Clean up temp directory
if [ -n "$TEMP_DIR" ] && [ -d "$TEMP_DIR" ]; then
    rm -rf "$TEMP_DIR"
fi

# Success message
echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                    Deployment Complete!                     ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo ""
echo "  1. Edit your configuration:"
echo -e "     ${YELLOW}nano $TARGET_DIR/artist_config.php${NC}"
echo ""
echo "  2. Update these fields:"
echo "     - name: Your artist name"
echo "     - email: Your email"
echo "     - site_name: Your site name"
echo "     - site_url: https://your-domain.com"
echo ""
echo "  3. Upload some artwork:"
echo "     - FTP images to: $TARGET_DIR/uploads/"
echo "     - Or set up OAuth for web uploads"
echo ""
echo "  4. Visit your site!"
echo ""
echo -e "${BLUE}Documentation:${NC}"
echo "  - README: $TARGET_DIR/README.md"
echo "  - Email setup: $TARGET_DIR/email-handler/README.md"
echo ""
echo -e "${GREEN}Happy creating! 🎨${NC}"
