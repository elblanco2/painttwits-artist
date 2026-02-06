# Artist Gallery Update System

## Overview

The Painttwits Artist Gallery includes an automatic update system that checks for new releases from GitHub and applies them with one click. This system is **only available for self-hosted installations** - managed painttwits.com subdomains receive updates automatically.

## Features

- ✅ One-click updates from GitHub releases
- ✅ Automatic backup before each update
- ✅ One-click rollback if something goes wrong
- ✅ Update notifications in Settings page
- ✅ Preserves config files and user data
- ✅ No manual file copying needed

## How It Works

### For Self-Hosted Artists

1. **Check for Updates**
   - Go to Settings page
   - "Software Updates" section shows current version
   - Automatically checks GitHub for new releases

2. **Apply Update**
   - Click "Update Now" button
   - System creates automatic backup
   - Downloads and extracts new release
   - Applies update (preserves your data)
   - Shows success message

3. **Rollback (if needed)**
   - If update fails, "Rollback" button appears
   - Restores from automatic backup
   - Returns to previous working version

### For Managed Painttwits Artists

Artists on `*.painttwits.com` subdomains don't see the update system - updates are applied centrally by the painttwits platform.

## Technical Details

### Files

- `version.php` - Current version info
- `api/check_update.php` - Checks GitHub for updates
- `api/apply_update.php` - Downloads and applies updates
- `api/rollback_update.php` - Restores from backup
- `settings.php` - Update UI (only shown for self-hosted)
- `backups/` - Auto-created backup directory

### What Gets Preserved During Updates

**Always Preserved:**
- `artist_config.php` - Your configuration
- `uploads/` - Your artwork files
- `logs/` - Log files
- `dzi/` - Deep zoom image tiles
- `backups/` - Previous backups
- `.git/` - Git repository (if present)

**Always Updated:**
- All PHP files (except config)
- JavaScript and CSS files
- Templates and assets
- API endpoints

### Backup Management

- Automatic backup before each update
- Last 5 backups kept automatically
- Older backups deleted to save space
- Backups stored in `backups/` directory
- Format: `backup_YYYY-MM-DD_HHmmss.zip`

## Requirements

- PHP 7.4+ with ZipArchive extension
- Write permissions on gallery directory
- Authenticated session (must be logged in)
- Internet connection to GitHub

## Creating Releases (For Developers)

### Automated Release Process

1. **Make changes and commit**
   ```bash
   git add .
   git commit -m "Your changes"
   git push origin main
   ```

2. **Create version tag**
   ```bash
   git tag v1.0.1
   git push origin v1.0.1
   ```

3. **GitHub Actions automatically:**
   - Updates `version.php` with new version
   - Creates release ZIP file
   - Generates release notes from commits
   - Publishes GitHub release

### Manual Release Process

If GitHub Actions isn't available:

1. Update `version.php`:
   ```php
   return [
       'version' => '1.0.1',
       'release_date' => '2026-02-06',
       'changelog_url' => 'https://github.com/elblanco2/painttwits-artist/releases/tag/v1.0.1',
       'min_php_version' => '7.4.0',
       'github_repo' => 'elblanco2/painttwits-artist'
   ];
   ```

2. Create ZIP file:
   ```bash
   zip -r painttwits-artist-v1.0.1.zip . \
     -x "*.git*" "node_modules/*" "artist_config.php" \
        "logs/*" "uploads/*" "dzi/*" "backups/*"
   ```

3. Create GitHub release:
   - Go to https://github.com/elblanco2/painttwits-artist/releases
   - Click "Draft a new release"
   - Tag: `v1.0.1`
   - Title: `Version 1.0.1`
   - Upload ZIP file
   - Write release notes
   - Publish release

## Version Numbering

Use semantic versioning (MAJOR.MINOR.PATCH):

- **MAJOR** (1.x.x) - Breaking changes, major features
- **MINOR** (x.1.x) - New features, non-breaking changes
- **PATCH** (x.x.1) - Bug fixes, minor improvements

Examples:
- `v1.0.0` - Initial release
- `v1.0.1` - Bug fix
- `v1.1.0` - New feature added
- `v2.0.0` - Major overhaul

## Troubleshooting

### Update Check Fails

**Problem:** "Failed to check for updates" error

**Solutions:**
- Check internet connection
- Verify GitHub API is accessible
- Check PHP curl extension is enabled
- Wait a few minutes (GitHub API rate limiting)

### Update Download Fails

**Problem:** Update fails during download

**Solutions:**
- Check disk space (need ~50MB free)
- Verify internet connection is stable
- Check PHP `max_execution_time` (needs 300s+)
- Try again later

### Update Extraction Fails

**Problem:** "Failed to open update ZIP" error

**Solutions:**
- Verify PHP ZipArchive extension is installed
- Check write permissions on gallery directory
- Check disk space
- Try rollback and update again

### Rollback Fails

**Problem:** "No backup files found" error

**Solutions:**
- Backup wasn't created (check permissions)
- Check `backups/` directory exists
- Manual restore: Upload previous version via FTP

### Update Applied But Site Broken

**Problem:** Update succeeded but site doesn't work

**Solutions:**
1. Click "Rollback to Previous Version" button
2. Check error logs in `logs/` directory
3. Verify `artist_config.php` wasn't overwritten
4. Report issue to GitHub: https://github.com/elblanco2/painttwits-artist/issues

## API Reference

### Check for Updates

```bash
GET /api/check_update.php
```

**Response:**
```json
{
  "success": true,
  "update_available": true,
  "current_version": "1.0.0",
  "latest_version": "1.0.1",
  "release_date": "2026-02-06T12:00:00Z",
  "release_notes": "## Changes\n- Bug fixes\n- New features",
  "download_url": "https://github.com/.../zipball/v1.0.1",
  "changelog_url": "https://github.com/.../releases/tag/v1.0.1"
}
```

### Apply Update

```bash
POST /api/apply_update.php
Content-Type: application/json

{
  "download_url": "https://github.com/.../zipball/v1.0.1"
}
```

**Response:**
```json
{
  "success": true,
  "steps": [
    "Created backups directory",
    "Backup created: backup_2026-02-06_120000.zip",
    "Downloading update from GitHub...",
    "Update downloaded: 2.5 MB",
    "Update extracted",
    "Critical files verified",
    "Updated 142 files",
    "Cleanup completed"
  ],
  "new_version": "1.0.1",
  "backup_file": "backup_2026-02-06_120000.zip"
}
```

### Rollback Update

```bash
POST /api/rollback_update.php
```

**Response:**
```json
{
  "success": true,
  "steps": [
    "Found backup: backup_2026-02-06_120000.zip",
    "Restored 142 files"
  ],
  "restored_version": "1.0.0",
  "backup_file": "backup_2026-02-06_120000.zip"
}
```

## Security Notes

- Update system requires authentication
- Only downloads from official GitHub releases
- Verifies critical files before applying
- Creates backup before any changes
- Never overwrites config files or user data
- Rate limited by GitHub API (60 requests/hour)

## Future Enhancements

Potential improvements for future versions:

- [ ] Scheduled automatic update checks
- [ ] Email notification of new releases
- [ ] Diff preview before applying update
- [ ] Selective file updates
- [ ] Update changelog viewer
- [ ] Backup restore UI (beyond just rollback)
- [ ] Update download progress indicator
- [ ] Beta/stable release channels

## Support

For issues with the update system:

- GitHub Issues: https://github.com/elblanco2/painttwits-artist/issues
- Documentation: https://github.com/elblanco2/painttwits-artist/blob/main/README.md
- Email: support@painttwits.com (for managed hosting)
