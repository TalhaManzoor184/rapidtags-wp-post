<?php
/**
 * RTAI Auto Blogger
 * Daily SEO blog post publisher for rapidtagsai.com
 * Uses Gemini AI to find trending topics and write full posts
 * Then publishes via WordPress REST API
 *
 * Usage: php rtai-auto-blogger.php
 * Config: Set env vars or edit config section below
 */

// ============================================================
// CONFIG — set these as environment variables on the server
// Or edit directly here (never commit real keys to git)
// ============================================================
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY_HERE');
define('WP_URL',         getenv('WP_URL')         ?: 'https://rapidtagsai.com/wp-blog');
define('WP_USERNAME',    getenv('WP_USERNAME')     ?: 'admin');
define('WP_APP_PASSWORD',getenv('WP_APP_PASSWORD') ?: 'YOUR_WP_APP_PASSWORD_HERE');
define('DEVTO_API_KEY',  getenv('DEVTO_API_KEY')   ?: '');
define('LOG_FILE',       __DIR__ . '/rtai-blogger.log');

// ============================================================
// LOGGING
// ============================================================
function log_msg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// ============================================================
// GEMINI API — with 4-model fallback chain
// ============================================================
function gemini_request($prompt, $json_mode = false) {
    $models = [
        'gemini-3.8-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-pro-preview',
    ];

    foreach ($models as $model) {
        log_msg("Trying Gemini model: $model");
        $result = gemini_call($model, $prompt, $json_mode);
        if ($result !== false) {
            log_msg("Success with model: $model");
            return $result;
        }
        log_msg("Model $model failed, trying next...");
    }

    log_msg("ERROR: All Gemini models failed!");
    return false;
}

function gemini_call($model, $prompt, $json_mode = false) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 4096,
        ]
    ];

    if ($json_mode) {
        $body['generationConfig']['responseMimeType'] = 'application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        log_msg("cURL error for $model: $curl_error");
        return false;
    }

    if ($http_code === 503) {
        // Server busy — wait and retry once
        log_msg("HTTP 503 for $model (server busy), retrying in 15s...");
        sleep(15);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $ch2 = curl_init($url);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
        ]);
        $response  = curl_exec($ch2);
        $http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($http_code !== 200) {
            log_msg("HTTP $http_code for $model after retry: " . substr($response, 0, 200));
            return false;
        }
    } elseif ($http_code !== 200) {
        log_msg("HTTP $http_code for $model: " . substr($response, 0, 200));
        return false;
    }

    $data = json_decode($response, true);
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        log_msg("Unexpected response from $model: " . substr($response, 0, 200));
        return false;
    }

    return $data['candidates'][0]['content']['parts'][0]['text'];
}

// ============================================================
// WORDPRESS REST API
// ============================================================
function wp_post($title, $content, $excerpt, $category_id, $tag_ids, $slug) {
    $url = WP_URL . '/wp-json/wp/v2/posts';

    $post_data = [
        'title'        => $title,
        'content'      => $content,
        'excerpt'      => $excerpt,
        'status'       => 'publish',
        'slug'         => $slug,
        'categories'   => [$category_id],
        'tags'         => $tag_ids,
    ];

    $credentials = base64_encode(WP_USERNAME . ':' . WP_APP_PASSWORD);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($post_data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . $credentials,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        log_msg("WP cURL error: $curl_err");
        return false;
    }

    $data = json_decode($response, true);

    if ($http_code === 201 && isset($data['id'])) {
        log_msg("Post published! ID: {$data['id']}, URL: {$data['link']}");
        return $data;
    }

    log_msg("WP publish failed (HTTP $http_code): " . substr($response, 0, 300));
    return false;
}

function get_wp_category($name) {
    // Map topic to best-matching existing category slug
    $category_map = [
        'youtube'   => 'youtube-tips',
        'tiktok'    => 'tiktok-tips',
        'instagram' => 'instagram-tips',
        'seo'       => 'seo-tips',
        'tags'      => 'youtube-tips',
        'hashtags'  => 'tiktok-tips',
    ];

    $slug = 'youtube-tips'; // default
    $name_lower = strtolower($name);
    foreach ($category_map as $keyword => $cat_slug) {
        if (strpos($name_lower, $keyword) !== false) {
            $slug = $cat_slug;
            break;
        }
    }

    // Look up category ID by slug
    $url = WP_URL . '/wp-json/wp/v2/categories?slug=' . urlencode($slug) . '&per_page=1';
    $credentials = base64_encode(WP_USERNAME . ':' . WP_APP_PASSWORD);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $cats = json_decode($response, true);
    if (!empty($cats) && isset($cats[0]['id'])) {
        return $cats[0]['id'];
    }

    // Fallback: get first category
    $url2 = WP_URL . '/wp-json/wp/v2/categories?per_page=1';
    $ch2 = curl_init($url2);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp2 = curl_exec($ch2);
    curl_close($ch2);
    $cats2 = json_decode($resp2, true);
    return isset($cats2[0]['id']) ? $cats2[0]['id'] : 1;
}

function create_or_get_tags($tag_names) {
    $credentials = base64_encode(WP_USERNAME . ':' . WP_APP_PASSWORD);
    $tag_ids = [];

    foreach (array_slice($tag_names, 0, 8) as $tag_name) {
        $tag_name = trim($tag_name);
        if (empty($tag_name)) continue;

        // Check if tag exists
        $url = WP_URL . '/wp-json/wp/v2/tags?search=' . urlencode($tag_name) . '&per_page=5';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $tags = json_decode($response, true);
        $found_id = null;
        foreach ((array)$tags as $t) {
            if (strtolower($t['name']) === strtolower($tag_name)) {
                $found_id = $t['id'];
                break;
            }
        }

        if ($found_id) {
            $tag_ids[] = $found_id;
        } else {
            // Create new tag
            $url2 = WP_URL . '/wp-json/wp/v2/tags';
            $ch2 = curl_init($url2);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['name' => $tag_name]),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . $credentials,
                ],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp2 = curl_exec($ch2);
            curl_close($ch2);
            $new_tag = json_decode($resp2, true);
            if (isset($new_tag['id'])) {
                $tag_ids[] = $new_tag['id'];
            }
        }
    }

    return $tag_ids;
}

// ============================================================
// DEV.TO API
// ============================================================
function devto_post($title, $html_content, $excerpt, $tags, $canonical_url) {
    // Strip AdSense script tags — dev.to blocks external scripts
    $clean_content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html_content);
    // Strip inline styles from TubeBuddy box (dev.to strips them anyway)
    // Keep the content, just clean it up
    $clean_content = strip_tags($clean_content, '<p><h2><h3><ul><ol><li><strong><em><a><blockquote><code><pre><br>');

    // dev.to uses markdown — convert basic HTML
    // For simplicity, send as markdown with HTML passthrough (dev.to accepts both)
    // Use the cleaned HTML directly — dev.to renders basic HTML fine

    // Tags: max 4, lowercase, no spaces
    $devto_tags = array_slice(array_map(function($t) {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $t));
    }, $tags), 0, 4);

    $post_data = [
        'article' => [
            'title'          => $title,
            'body_markdown'  => $clean_content,
            'published'      => true,
            'canonical_url'  => $canonical_url,
            'description'    => $excerpt,
            'tags'           => $devto_tags,
        ]
    ];

    $ch = curl_init('https://dev.to/api/articles');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($post_data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . DEVTO_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        log_msg("dev.to cURL error: $curl_err");
        return false;
    }

    $data = json_decode($response, true);

    if ($http_code === 201 && isset($data['id'])) {
        log_msg("dev.to post published! ID: {$data['id']}, URL: {$data['url']}");
        return $data;
    }

    log_msg("dev.to publish failed (HTTP $http_code): " . substr($response, 0, 300));
    return false;
}

// ============================================================
// MAIN FLOW
// ============================================================
log_msg("=== RTAI Auto Blogger Starting ===");

// Step 1: Get trending topic
log_msg("Step 1: Finding trending topic...");

$today = date('Y-m-d');
$topic_prompt = "You are an SEO expert for a YouTube tag generator website (rapidtagsai.com).
Today is {$today}.

We need to rank for NON-BRANDED keywords. Pick ONE topic from these HIGH-PRIORITY keyword clusters
(rotate through them — do not repeat the same topic twice):

PRIORITY 1 — Tag Generator cluster (most impressions, position 23-29):
- \"tags generator\", \"tag generator\", \"generate tags\", \"generator tag\", \"ai tag generator\"
  → Post ideas: \"Best Free Tag Generator for YouTube & TikTok 2027\", \"How to Generate Tags for YouTube Videos\"

PRIORITY 2 — TikTok Hashtag Generator (308 impressions, position 53 — huge gap):
- \"ai tiktok hashtag generator\", \"tiktok tags generator\", \"tiktok tag generator\"
  → Post ideas: \"Best AI TikTok Hashtag Generator Free 2027\", \"TikTok Tags Generator: Get Viral Fast\"

PRIORITY 3 — YouTube SEO Tools cluster (position 34-59):
- \"free youtube seo tools\", \"youtube seo tool free\", \"yt seo tools\", \"free seo tools for youtube\"
  → Post ideas: \"5 Free YouTube SEO Tools to Grow Your Channel 2027\"

PRIORITY 4 — Clickbait / Content Tools (position 42-52):
- \"clickbait title generator\", \"video idea generator\"
  → Post ideas: \"Best Clickbait Title Generator for YouTube 2027\"

PRIORITY 5 — Monetization & CPM (position 44-71):
- \"youtube monetization requirements 2026\", \"highest cpm youtube niches 2026\"
  → Post ideas: \"YouTube Monetization Requirements 2027: Complete Guide\"

Rules:
1. Pick the cluster with the most search volume opportunity today
2. Include current year (2026 or 2027) in the title for freshness
3. The post MUST mention rapidtagsai.com as the free tool
4. Focus on what creators actually search for

Return ONLY a JSON object like:
{\"topic\": \"Best AI TikTok Hashtag Generator Free 2027\", \"keyword\": \"ai tiktok hashtag generator\", \"tags\": [\"tiktok hashtag generator\", \"tiktok tags\", \"ai hashtags\", \"tiktok seo\", \"2027\"], \"category\": \"tiktok-tips\"}";

$topic_json = gemini_request($topic_prompt, true);
if (!$topic_json) {
    log_msg("FATAL: Could not get topic from Gemini");
    exit(1);
}

// Parse topic
$topic_json = trim($topic_json);
// Strip markdown code blocks if present
$topic_json = preg_replace('/^```json\s*/m', '', $topic_json);
$topic_json = preg_replace('/^```\s*/m', '', $topic_json);
$topic_data = json_decode($topic_json, true);

if (!$topic_data || !isset($topic_data['topic'])) {
    log_msg("ERROR: Invalid topic JSON: $topic_json");
    exit(1);
}

$topic    = $topic_data['topic'];
$keyword  = $topic_data['keyword'] ?? $topic;
$tags     = $topic_data['tags'] ?? ['youtube', 'seo', '2026'];
$category = $topic_data['category'] ?? 'youtube-tips';

log_msg("Topic selected: $topic");
log_msg("Keyword: $keyword");

// Step 2: Generate full post
log_msg("Step 2: Writing full blog post...");

$post_prompt = "Write a complete, SEO-optimized WordPress blog post about: \"{$topic}\"

Target keyword: \"{$keyword}\"

Requirements:
- Length: 800-1000 words
- Use proper HTML formatting (use <h2>, <p>, <ul>, <li>, <strong>, <ol> tags)
- Include the keyword naturally 4-6 times
- Structure: intro, 4-5 H2 sections, FAQ section, closing CTA
- In the CTA/closing, link to https://rapidtagsai.com/wp-blog/ as the free tool
- Write for a YouTube/TikTok creator audience
- Practical, actionable advice
- Professional but friendly tone
- Near the end of the post (before or after the FAQ), include a natural recommendation for TubeBuddy as a paid tool for serious YouTubers. Affiliate link: https://www.tubebuddy.com/pricing?a=RAPIDTAGS
  Write something like: If you want to take your YouTube SEO even further, TubeBuddy (link: https://www.tubebuddy.com/pricing?a=RAPIDTAGS) is one of the best tools for keyword research and tag suggestions. Use rel=sponsored and target=_blank on the link.
  Keep it natural — 2-3 sentences max, not spammy

Also at the top of your response, before the HTML, add a line:
TITLE: [exact blog post title]
SLUG: [url-friendly-slug-with-hyphens]
EXCERPT: [1-2 sentence meta description under 160 chars]

Then the full HTML content.";

$raw_post = gemini_request($post_prompt);
if (!$raw_post) {
    log_msg("FATAL: Could not generate post content");
    exit(1);
}

// Parse title, slug, excerpt from the response
$lines = explode("\n", $raw_post);
$post_title   = '';
$post_slug    = '';
$post_excerpt = '';
$content_lines = [];
$in_content = false;

foreach ($lines as $line) {
    $line_trimmed = trim($line);
    if (!$in_content && preg_match('/^TITLE:\s*(.+)$/i', $line_trimmed, $m)) {
        $post_title = trim($m[1]);
    } elseif (!$in_content && preg_match('/^SLUG:\s*(.+)$/i', $line_trimmed, $m)) {
        $post_slug = strtolower(trim($m[1]));
        $post_slug = preg_replace('/[^a-z0-9\-]/', '-', $post_slug);
        $post_slug = trim($post_slug, '-');
    } elseif (!$in_content && preg_match('/^EXCERPT:\s*(.+)$/i', $line_trimmed, $m)) {
        $post_excerpt = trim($m[1]);
        $in_content = true; // content starts after excerpt line
    } elseif ($in_content) {
        $content_lines[] = $line;
    }
}

$post_content = implode("\n", $content_lines);

// Fallbacks
if (empty($post_title)) {
    $post_title = $topic;
}
if (empty($post_slug)) {
    $post_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $topic));
    $post_slug = trim($post_slug, '-') . '-' . date('Y');
}
if (empty($post_excerpt)) {
    $post_excerpt = "Learn everything about {$keyword} in 2026. Complete guide with tips, tools, and strategies.";
}
if (empty($post_content)) {
    $post_content = $raw_post; // fallback: use full response as content
}

// ============================================================
// INJECT ADSENSE ADS INTO POST CONTENT
// ============================================================
$ad_top = '
<!-- AdSense: Post Top -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3927136769668680" crossorigin="anonymous"></script>
<ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-3927136769668680" data-ad-slot="8766667796" data-ad-format="auto" data-full-width-responsive="true"></ins>
<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
';

$ad_mid = '
<!-- AdSense: Post Mid -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3927136769668680" crossorigin="anonymous"></script>
<ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-3927136769668680" data-ad-slot="4520621824" data-ad-format="auto" data-full-width-responsive="true"></ins>
<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
';

$ad_bottom = '
<!-- AdSense: Post Bottom -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3927136769668680" crossorigin="anonymous"></script>
<ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-3927136769668680" data-ad-slot="5855679405" data-ad-format="auto" data-full-width-responsive="true"></ins>
<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
';

// Insert top ad at the very beginning
$post_content = $ad_top . $post_content;

// Insert mid ad after the 2nd <h2> tag (middle of content)
$h2_count = 0;
$mid_inserted = false;
$post_content = preg_replace_callback('/<h2[\s>]/i', function($match) use (&$h2_count, &$mid_inserted, $ad_mid) {
    $h2_count++;
    if ($h2_count === 2 && !$mid_inserted) {
        $mid_inserted = true;
        return $ad_mid . $match[0];
    }
    return $match[0];
}, $post_content);

// Insert bottom ad at the very end
$post_content = $post_content . $ad_bottom;

log_msg("AdSense ads injected (top, mid after 2nd H2, bottom)");

// ============================================================
// INJECT TUBEBUDDY AFFILIATE RECOMMENDATION (hardcoded)
// ============================================================
$tubebuddy_box = '
<div style="background:#f0f7ff;border-left:4px solid #1a73e8;padding:16px 20px;margin:24px 0;border-radius:4px;">
  <p style="margin:0 0 8px 0;font-weight:bold;">🚀 Want to Grow Your YouTube Channel Faster?</p>
  <p style="margin:0;">Pair RapidTags with <a href="https://www.tubebuddy.com/pricing?a=RAPIDTAGS" rel="sponsored" target="_blank" style="color:#1a73e8;font-weight:bold;">TubeBuddy</a> — the #1 YouTube-certified tool for keyword research, tag optimization, and channel analytics. Used by 10M+ creators. <a href="https://www.tubebuddy.com/pricing?a=RAPIDTAGS" rel="sponsored" target="_blank" style="color:#1a73e8;">Try TubeBuddy free →</a></p>
</div>
';

// Insert TubeBuddy box before the last </p> before $ad_bottom (i.e. after content, before bottom ad)
// Place it just before the bottom ad
$post_content = str_replace($ad_bottom, $tubebuddy_box . $ad_bottom, $post_content);

log_msg("TubeBuddy affiliate box injected");
log_msg("Post slug: $post_slug");

// Step 3: Get category ID
log_msg("Step 3: Getting category ID...");
$category_id = get_wp_category($category);
log_msg("Category ID: $category_id");

// Step 4: Create/get tags
log_msg("Step 4: Processing tags: " . implode(', ', $tags));
$tag_ids = create_or_get_tags($tags);
log_msg("Tag IDs: " . implode(', ', $tag_ids));

// Step 5: Publish post
log_msg("Step 5: Publishing to WordPress...");
$result = wp_post($post_title, $post_content, $post_excerpt, $category_id, $tag_ids, $post_slug);

if ($result) {
    log_msg("=== SUCCESS! Post published ===");
    log_msg("Post ID: {$result['id']}");
    log_msg("Post URL: {$result['link']}");
    log_msg("Title: {$result['title']['rendered']}");
} else {
    log_msg("=== FAILED: Post was not published ===");
    exit(1);
}

// Step 6: Publish to dev.to (cross-post with canonical URL)
if (DEVTO_API_KEY && $result) {
    log_msg("Step 6: Cross-posting to dev.to...");
    $canonical_url = $result['link'];
    devto_post($post_title, $post_content, $post_excerpt, $tags, $canonical_url);
} else {
    log_msg("Step 6: Skipping dev.to (no API key or WP failed)");
}

log_msg("=== RTAI Auto Blogger Done ===");
