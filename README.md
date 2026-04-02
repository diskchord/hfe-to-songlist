# HFE to Songlist (WordPress Plugin)

This plugin adds a shortcode that lets users upload an `.hfe` disk image and generate a song list from MIDI and Yamaha E-SEQ/FIL files.

## What It Produces

Output format:

```text
ALBUM NAME
  01. Song 1 Name
  02. Song 2 Name
```

The plugin also shows a table with `#`, parsed song title, and source filename.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- `gw` (Greaseweazle CLI) available on the server `PATH`
- `7z` (7-Zip CLI) available on the server `PATH`

## Installation

1. Copy this plugin folder into `wp-content/plugins/hfe-to-songlist`.
2. Activate **HFE to Songlist** in WordPress.
3. Add shortcode to any page:

```text
[hfe_songlist]
```

## Validation and Safety

The plugin validates:

- uploaded extension is `.hfe`
- HFE signature is `HXCPICFE`
- geometry/bitrate map to supported floppy classes:
  - 80 tracks, 2 sides, 250 kbps (720 KB)
  - 80 tracks, 2 sides, 500 kbps (1440 KB)
- converted image is exactly `737280` or `1474560` bytes
- converted image can be listed as a FAT filesystem by `7z`

Temporary files are created under WordPress uploads (`hfe-songlist-temp`) and removed after processing.

## Notes on Song Title Parsing

- MIDI titles: reads text meta events (`0x03` track name, fallback `0x01`) from track chunks.
- E-SEQ/FIL titles: reads title bytes at offset `0x57` length `0x20`, normalizes ASCII spaces.
- `PIANODIR.FIL` is skipped as a control/directory file.

## Optional Binary Overrides

If needed, define constants in `wp-config.php`:

```php
define('HFE_SONGLIST_GW_BINARY', '/path/to/gw');
define('HFE_SONGLIST_7Z_BINARY', '/path/to/7z');
```

Or use filters:

- `hfe_songlist_gw_binary`
- `hfe_songlist_7z_binary`
