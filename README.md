# RTAI Auto Blogger

Daily SEO blog post publisher for rapidtagsai.com

Uses Gemini AI to find trending topics and write full posts, then publishes via WordPress REST API.

## Setup

### Environment Variables (recommended)

Set these on your server before running:

```bash
export GEMINI_API_KEY="your-gemini-api-key"
export WP_URL="https://rapidtagsai.com/wp-blog"
export WP_USERNAME="admin"
export WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
```

### cPanel Cron Job

Add this cron to run daily at 3 AM:

```
0 3 * * * GEMINI_API_KEY="your-key" WP_APP_PASSWORD="your-pass" php /home/rapidtagsai/public_html/wp-blog/wp-content/rtai-cron/rtai-auto-blogger.php
```

### Manual Run

```bash
php rtai-auto-blogger.php
```

## How It Works

1. **Step 1** — Ask Gemini for a trending SEO topic (YouTube/TikTok/Instagram niche)
2. **Step 2** — Ask Gemini to write a full 800-1000 word blog post
3. **Step 3** — Look up the best matching WordPress category
4. **Step 4** — Create or find tag IDs in WordPress
5. **Step 5** — Publish the post via WordPress REST API

## Security

- Never commit API keys to git
- Use environment variables or a local `config.local.php` (excluded in `.gitignore`)
- WordPress Application Password is used (not your main password)

## Get a Gemini API Key

https://aistudio.google.com/apikey
