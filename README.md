# Artist Portfolio Template

**Your own art gallery website in 5 minutes**

This template gives you a professional artist portfolio with deep zoom viewing, responsive images, and optional email-to-gallery uploads. Perfect for shared hosting (Bluehost, HostGator, SiteGround, etc).

---

## What You Get

```
┌─────────────────────────────────────────────────────────────┐
│  YOUR-SITE.COM                                    [Login]   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│     ┌─────────┐  ┌─────────┐  ┌─────────┐                  │
│     │ ▓▓▓▓▓▓▓ │  │ ░░░░░░░ │  │ ▒▒▒▒▒▒▒ │   Gallery Grid  │
│     │ ▓▓▓▓▓▓▓ │  │ ░░░░░░░ │  │ ▒▒▒▒▒▒▒ │                  │
│     └─────────┘  └─────────┘  └─────────┘                  │
│                                                             │
│     "Sunset"      "Portrait"    "Abstract"                 │
│      24x36          16x20         30x40                    │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│  Click any artwork to view full screen with deep zoom →    │
└─────────────────────────────────────────────────────────────┘
```

**Features:**
- Masonry grid gallery layout
- Deep zoom viewer (OpenSeaDragon) - collectors see every brushstroke
- Auto-generates multiple image sizes (thumbnail, medium, large, social)
- Mobile responsive
- SEO-friendly with proper meta tags
- Social sharing (Twitter, Bluesky, Pinterest)
- Optional: Email-to-gallery uploads
- Optional: Google OAuth login for editing

---

## Quick Deploy (5 Minutes)

### Step 1: Download & Upload

**Option A: Download ZIP**
1. [Download ZIP](https://github.com/elblanco2/artist-portfolio/archive/main.zip)
2. Extract and upload contents to your web hosting (via cPanel File Manager or FTP)

**Option B: Git Clone**
```bash
git clone https://github.com/elblanco2/artist-portfolio.git
# Upload contents to your web hosting
```

**Option C: One-Line Deploy (SSH)**
```bash
bash <(curl -sSL https://raw.githubusercontent.com/elblanco2/artist-portfolio/main/deploy.sh) /path/to/public_html
```

### Step 2: Run Setup Wizard

Open your browser and visit:
```
https://YOUR-DOMAIN.COM/setup.php
```

The setup wizard will:
- Ask for your name and email
- Configure your gallery automatically
- Optionally connect you to the painttwits network for discovery

**That's it!** No config files to edit manually.

---

## Manual Configuration (Alternative)

If you prefer to configure manually instead of using the setup wizard:

1. Copy `artist_config.sample.php` to `artist_config.php`
2. Edit with your details:

```php
<?php
return [
    // === YOUR INFO ===
    'name' => 'Jane Artist',
    'email' => 'jane@example.com',
    'location' => 'Brooklyn, NY',
    'bio' => 'Contemporary painter exploring light and shadow.',

    // === YOUR SITE ===
    'site_name' => 'Jane Artist Studio',
    'site_domain' => 'janeartist.com',
    'site_url' => 'https://janeartist.com',

    // === DISPLAY OPTIONS ===
    'show_prices' => true,       // Show prices on artwork
    'contact_form' => true,      // Enable contact form
    'show_site_badge' => false,  // Hide "powered by" badge
];
```

3. Delete `setup.php` after configuration is complete

---

## Uploading Artwork

### Method 1: FTP/File Manager (Simplest)

1. Upload images to the `uploads/` folder
2. Name them descriptively: `sunset-over-miami-24x36.jpg`
3. They'll appear on your site automatically

### Method 2: Web Upload (Requires OAuth)

1. Set up Google OAuth (see Configuration above)
2. Log in to your site
3. Click "Upload" button
4. Drag & drop images

### Method 3: Email-to-Gallery

See [email-handler/README.md](email-handler/README.md) for setup.

Send an email like:
```
To: artwork@yoursite.com
Subject: "Sunset Over Miami" 24x36 oil on canvas
Attachment: sunset.jpg
```

---

## File Structure

```
your-site/
├── index.php              # Gallery home page
├── art.php                # Single artwork view
├── zoom.php               # Deep zoom viewer
├── upload.php             # Upload handler
├── auth.php               # OAuth login
├── settings.php           # Artist settings
├── artist_config.php      # Your configuration (create this!)
├── artist_config.sample.php
├── .htaccess              # URL rewrites
├── assets/
│   ├── css/
│   │   ├── style.css      # Main styles
│   │   └── zoom.css       # Zoom viewer styles
│   └── js/
│       └── upload.js      # Upload handling
├── uploads/               # Your artwork goes here
│   └── dzi/              # Auto-generated zoom tiles
└── email-handler/         # Optional email uploads
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
| DZI tiles | Pyramid | Deep zoom (images ≥3000px) |

Example: Upload `sunset.jpg` (4000x3000px) and get:
```
uploads/
├── sunset.jpg           # Original
├── sunset_large.jpg     # 1200px
├── sunset_medium.jpg    # 800px
├── sunset_small.jpg     # 400px
├── sunset_social.jpg    # 1200x630
└── dzi/
    ├── sunset.dzi       # Tile descriptor
    └── sunset_files/    # Tile pyramid
        ├── 0/
        ├── 1/
        └── ...
```

---

## Requirements

**Minimum:**
- PHP 7.4+
- GD extension (usually pre-installed)

**Recommended:**
- PHP 8.0+
- Imagick extension (better image quality)
- IMAP extension (for email uploads)

**Check your hosting:**
```php
<?php
phpinfo();
// Look for: GD, Imagick, IMAP
```

---

## Hosting Recommendations

Works great on:
- **Shared hosting**: Bluehost, HostGator, SiteGround, DreamHost
- **VPS**: DigitalOcean, Linode, Vultr
- **Managed**: Cloudways, RunCloud

Typical cost: **$3-10/month** for shared hosting

---

## Troubleshooting

**Images not showing?**
- Check `uploads/` folder permissions (755)
- Verify PHP can write to directory

**Deep zoom not working?**
- Images must be ≥3000px for DZI generation
- Check PHP memory_limit (recommend 256M for large images)

**OAuth login failing?**
- Verify Google Client ID is correct
- Check callback URL matches exactly
- Email in config must match Google account

**Need help?**
- Check [TROUBLESHOOTING.md](../../TROUBLESHOOTING.md)
- Open issue on GitHub
- Email support@painttwits.com

---

## Join the painttwits Network (Optional)

Want your gallery discoverable on painttwits.com?

**Easy way:** Check "Join the painttwits network" during setup wizard.

**Manual way:** Add to your config:
```php
'painttwits_network' => [
    'enabled' => true,
    'sample_artwork' => 'best-piece.jpg',
],
```

Benefits:
- Appear in location-based artist search
- Backlink from painttwits.com
- Shared Google OAuth (no need to set up your own)
- Video intro tool access
- Part of curated artist community
- Keep your own domain

---

## License

MIT License - free for personal and commercial use.

---

*Made for artists who want to own their online presence.*
