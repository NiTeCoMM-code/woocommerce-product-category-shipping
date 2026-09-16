# Plugin Assets

WordPress.org requires the following asset files in the `/assets/` directory:

| File | Dimensions | Purpose |
|------|-----------|---------|
| `icon.svg` or `icon.png` | 256×256 px | Plugin icon shown in the WordPress.org directory |
| `banner-772x250.svg` or `banner-772x250.png` | 772×250 px | Banner shown at the top of the plugin page |
| `screenshot-1.png` | 800×600 px recommended | Screenshots of the admin settings page |

**Important:** SVG files work for GitHub but WordPress.org SVN currently requires PNG for `icon.png` and `banner-772x250.png`. Please convert the provided SVGs to PNG before the SVN commit, or replace them entirely with branded PNG assets.

A placeholder icon and banner have been provided as SVGs in this directory. Replace them with properly sized PNG files before publishing to WordPress.org.
