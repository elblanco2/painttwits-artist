# painttwits-artist

**Self-hosted artist gallery for the [painttwits](https://painttwits.com) network**

A complete artist portfolio site with deep zoom viewing, email-to-gallery uploads, account management, and optional federation with the painttwits discovery network. Designed for shared hosting (Bluehost, HostGator, SiteGround, etc).

---

## Features

- Masonry grid gallery layout
- Deep zoom viewer (OpenSeadragon) — collectors see every brushstroke
- Auto-generates multiple image sizes (thumbnail, medium, large, social)
- Email-to-gallery — email `newart@painttwits.com` from your phone, artwork appears on your site (requires network registration)
- Account settings with backup export and account deletion
- Location-based artist map (via painttwits network)
- Social sharing (Bluesky, Twitter, Pinterest)
- Mobile responsive
- SEO-friendly meta tags and Open Graph
- Google OAuth login
- Dark mode support

---

## Quick Deploy (5 Minutes)

### Step 1: Download & Upload

**Option A: Download ZIP**
1. [Download ZIP](https://github.com/elblanco2/painttwits-artist/archive/main.zip)
2. Extract and upload contents to your web hosting (via cPanel File Manager or FTP)

**Option B: Git Clone**
```bash
git clone https://github.com/elblanco2/painttwits-artist.git
# Upload contents to your web hosting
```

**Option C: One-Line Deploy (SSH)**
```bash
bash <(curl -sSL https://raw.githubusercontent.com/elblanco2/painttwits-artist/main/deploy.sh) /path/to/public_html
```

### Step 2: Run Setup Wizard

Open your browser and visit:
```
https://YOUR-DOMAIN.COM/setup.php
```

The setup wizard will:
- Ask for your name and email
- Configure your gallery automatically
- Optionally register you with the painttwits network for discovery

**That's it.** No config files to edit manually.

---

## Manual Configuration (Alternative)

If you prefer to skip the setup wizard:

1. Copy `artist_config.sample.php` to `artist_config.php`
2. Edit with your details:

```php
<?php
return [
    'name' => 'Jane Artist',
    'email' => 'jane@example.com',
    'location' => 'Brooklyn, NY',
    'bio' => 'Contemporary painter exploring light and shadow.',

    'site_name' => 'Jane Artist Studio',
    'site_domain' => 'janeartist.com',
    'site_url' => 'https://janeartist.com',

    'show_prices' => true,
    'contact_form' => true,
    'show_site_badge' => false,
];
```

3. Delete `setup.php` after configuration is complete

---

## Uploading Artwork

### Method 1: FTP / File Manager

Upload images to the `uploads/` folder. They appear on your site automatically.

### Method 2: Web Upload

Log in via Google OAuth and use the upload button. Drag and drop supported.

### Method 3: Email-to-Gallery (requires painttwits network)

If you registered with the painttwits network during setup, you can email artwork directly to your gallery:

```
To: newart@painttwits.com
Subject: "Sunset Over Miami" 24x36 oil on canvas
Attachment: sunset.jpg
```

The painttwits server identifies you by your sender email, processes the image (generates thumbnails, DZI tiles for deep zoom), and pushes everything to your site automatically. Title, dimensions, and medium are parsed from the subject line.

This does **not** require any email configuration on your hosting — all processing happens on painttwits.com and the finished artwork is delivered to your site via API.

---

## Account Management

### Settings

Available at `/settings.php` when logged in:
- Update profile (name, bio, location)
- Set map location for painttwits network discovery
- Manage gallery preferences

### Backup & Export

Download a ZIP of all your artwork, metadata, and profile before making changes:
- Available at Settings > Danger Zone > "Download All Artwork (ZIP)"
- Includes originals, resized images, DZI tiles, and metadata

### Account Deletion

Delete your account from Settings > Danger Zone:
- Downloads available before deletion
- Removes all local files and uploads
- Notifies the painttwits central server to free your email for re-registration
- Managed subdomain artists get their subdomain released

---

## Software Updates

### Automatic Updates (Self-Hosted)

If you're running your own domain, updates are easy:

1. Go to **Settings → Software Updates**
2. System checks GitHub for new releases
3. Click **"Update Now"** when available
4. Automatic backup created before updating
5. Update completes in 1-2 minutes

**Features:**
- ✅ One-click updates from GitHub releases
- ✅ Automatic backup before each update
- ✅ One-click rollback if needed
- ✅ Preserves your config and artwork
- ✅ No manual file copying

### Manual Updates

If automatic updates aren't available:

1. **Backup first!** Download your artwork via Settings
2. Download the [latest release](https://github.com/elblanco2/painttwits-artist/releases)
3. Extract and upload files to your server
4. **Keep your existing `artist_config.php`** (don't overwrite!)
5. Verify site works

**What gets preserved:**
- `artist_config.php` - Your settings
- `uploads/` - Your artwork
- `logs/` - Logs
- `backups/` - Previous backups

**What gets updated:**
- All PHP, JS, CSS files
- Templates and layouts
- API endpoints

See [UPDATE_SYSTEM.md](UPDATE_SYSTEM.md) for detailed documentation.

---

## File Structure

```
your-site/
├── index.php                # Gallery home page
├── art.php                  # Single artwork view
├── zoom.php                 # Deep zoom viewer
├── upload.php               # Upload handler
├── auth.php                 # OAuth login
├── settings.php             # Artist settings, backup, deletion
├── setup.php                # First-run setup wizard
├── artist_config.php        # Your configuration (generated by setup)
├── artist_config.sample.php # Example config
├── artwork_meta.json        # Artwork metadata store
├── .htaccess                # URL rewrites
├── api/
│   ├── export.php           # Artwork ZIP export
│   ├── location.php         # Map location save/lookup
│   ├── receive_artwork.php  # Email-to-gallery receiver
│   ├── check_update.php     # Check for software updates
│   ├── apply_update.php     # Apply updates
│   └── rollback_update.php  # Rollback to previous version
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   └── zoom.css
│   └── js/
│       ├── upload.js
│       └── theme.js
├── uploads/                 # Your artwork
│   └── dzi/                 # Auto-generated zoom tiles
├── backups/                 # Auto-created backups (from updates)
├── email-handler/           # Optional email upload config
└── version.php              # Current version info (for updates)
```

---

## Image Processing

When you upload an image, the system automatically creates:

| Version | Size | Use |
|---------|------|-----|
| Original | Full res | Archive, zoom source |
| `_large.jpg` | 1200px | Detail view |
| `_medium.jpg` | 800px | Gallery grid |
| `_small.jpg` | 400px | Thumbnails |
| `_social.jpg` | 1200x630 | Social media cards |
| DZI tiles | Pyramid | Deep zoom (images 3000px+) |

---

## Requirements

**Minimum:**
- PHP 7.4+
- GD extension

**Recommended:**
- PHP 8.0+
- Imagick extension (better image quality)
- IMAP extension (for email uploads)

---

## painttwits Network

Joining the painttwits network is optional. When connected, your gallery:
- **Email-to-gallery** — email artwork to `newart@painttwits.com` and it appears on your site automatically (no email server config needed on your end)
- Appears on the painttwits.com discovery map
- Gets a backlink from painttwits.com
- Can use shared Google OAuth (no need to set up your own)
- Gets social media posting to the painttwits Bluesky account
- Keeps your own domain — you own your content

Without the network, your gallery still works — you just upload artwork manually via the web interface or FTP.

To join, check "Join the painttwits network" during the setup wizard, or add to your config:

```php
'painttwits_network' => [
    'enabled' => true,
    'sample_artwork' => 'best-piece.jpg',
],
```

---

## License

MIT License — free for personal and commercial use.

---

*Made for artists who want to own their online presence.*
