# LB Reviews – Google & Trustpilot

WordPress plugin that shows Google and Trustpilot reviews in a grid or slider,
with a shortcode and a native element for Uncode / WPBakery.
Built for [lucillabellini.net](https://www.lucillabellini.net/).

## Features

- **Google**: live reviews, rating and total via Places API (cached, never stored), full archive via Google Takeout import.
- **Trustpilot**: CSV / JSON import, or TrustBox widget.
- **Layouts**: grid with "Show more", slider (no libraries), "Read more" on long texts.
- **Lists**: group reviews and show a list on specific pages; or pick reviews by ID.
- **Review buttons**: "Leave a review on Google / Trustpilot".
- **Updates from GitHub**: WordPress shows new releases on the Plugins screen.

## Install

Download `lb-reviews.zip` from the [latest release](../../releases/latest),
then in WordPress: **Plugins → Add New → Upload Plugin**.

## Usage

```
[lb_reviews]
[lb_reviews layout="slider" columns="3" autoplay="6"]
[lb_reviews list="family"]
[lb_reviews ids="812,790,775"]
[lb_reviews_button source="google"]
```

All attributes: **LB Reviews → Settings & import → How to use** in WordPress.

## Releasing a new version

1. Update the version in `lb-reviews.php` (header `Version:` and `LBR_VERSION`, same number) and add a line to the changelog in `readme.txt`.
2. Commit and push to `main`.

The GitHub Action sees the new version, builds `lb-reviews.zip` and publishes the release `vX.Y.Z`
(pushes that don't change the version are ignored). It can also be run by hand from the **Actions** tab.
Sites with the plugin installed see the update within a few hours, or right away with
**Plugins → LB Reviews → Check for updates**.

## License

GPL-2.0-or-later
