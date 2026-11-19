<?php
// meta_renderer.php - Dynamically inject Open Graph meta tags into React index.html for Berita & Karya detail links
require_once __DIR__ . '/config/helpers.php';

// Helper function to clean text (strip HTML & Markdown) for meta description
function clean_meta_text(string $text, int $maxLength = 150): string {
    // 1. Strip HTML tags
    $clean = strip_tags($text);
    // 2. Remove Markdown links [text](url) -> text
    $clean = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $clean);
    // 3. Remove Markdown headings, bold, italic, code blocks, blockquotes
    $clean = preg_replace('/[#*_~`>]/', ' ', $clean);
    // 4. Normalize spaces and newlines
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);

    if (mb_strlen($clean) > $maxLength) {
        return mb_substr($clean, 0, $maxLength - 3) . '...';
    }
    return $clean;
}

// Helper function to check if URL points to an image
function is_image_url(string $url): bool {
    if (empty($url)) return false;
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return false;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

// Default fallback metadata
$siteName = 'MTs Muhammadiyah 06 Purbalingga';
$defaultTitle = 'MTs Muhammadiyah 06 Purbalingga | Portal Resmi';
$defaultDesc = 'Portal Resmi MTs Muhammadiyah 06 Purbalingga - Berita, Karya Siswa, PMB, dan Pengumuman Kelulusan.';
$defaultImage = base_url() . '/img/gedung-mts.jpg';

$type = $_GET['type'] ?? '';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

// Detect type & id from REQUEST_URI if missing from query params
if (!$type || !$id) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#^/(berita|karya)/([0-9]+)#i', $uriPath, $matches)) {
        $type = strtolower($matches[1]);
        $id = (int)$matches[2];
    }
}

$pageTitle = $defaultTitle;
$metaDesc = $defaultDesc;
$ogImage = $defaultImage;
$ogUrl = base_url() . ($type && $id ? "/{$type}/{$id}" : '');
$ogType = ($type === 'karya') ? 'website' : 'article';

if ($id && ($type === 'berita' || $type === 'karya')) {
    try {
        if ($type === 'berita') {
            $stmt = $pdo->prepare('SELECT title, excerpt, content, image_url FROM announcements WHERE id = ? AND published = 1');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if ($item) {
                $rawTitle = trim($item['title'] ?? '');
                if ($rawTitle !== '') {
                    $pageTitle = $rawTitle . ' - ' . $siteName;
                }
                $rawText = $item['excerpt'] ?? $item['content'] ?? '';
                if ($rawText !== '') {
                    $metaDesc = clean_meta_text($rawText, 150);
                }
                $rawImg = absolute_url($item['image_url'] ?? '');
                if ($rawImg && is_image_url($rawImg)) {
                    $ogImage = $rawImg;
                }
            }
        } elseif ($type === 'karya') {
            $stmt = $pdo->prepare('SELECT title, description, media_url, category FROM student_works WHERE id = ? AND status = "approved"');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if ($item) {
                $rawTitle = trim($item['title'] ?? '');
                if ($rawTitle !== '') {
                    $pageTitle = $rawTitle . ' - Karya Siswa ' . $siteName;
                }
                $rawText = $item['description'] ?? '';
                if ($rawText !== '') {
                    $metaDesc = clean_meta_text($rawText, 150);
                }
                $mediaList = absolute_media_list($item['media_url'] ?? '');
                foreach ($mediaList as $m) {
                    if (is_image_url($m)) {
                        $ogImage = $m;
                        break;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('meta_renderer.php error: ' . $e->getMessage());
    }
}

// Load React build index.html
$indexPath = __DIR__ . '/index.html';
if (!file_exists($indexPath)) {
    http_response_code(404);
    echo "index.html not found";
    exit;
}

$html = file_get_contents($indexPath);

// Prepare escaped strings for HTML attributes
$escTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
$escDesc = htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8');
$escImage = htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8');
$escUrl = htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8');
$escSiteName = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');

// Build meta tags block
$metaBlock = <<<HTML
    <title>{$escTitle}</title>
    <meta name="description" content="{$escDesc}" />
    <meta property="og:type" content="{$ogType}" />
    <meta property="og:site_name" content="{$escSiteName}" />
    <meta property="og:title" content="{$escTitle}" />
    <meta property="og:description" content="{$escDesc}" />
    <meta property="og:image" content="{$escImage}" />
    <meta property="og:url" content="{$escUrl}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="{$escTitle}" />
    <meta name="twitter:description" content="{$escDesc}" />
    <meta name="twitter:image" content="{$escImage}" />
HTML;

// Strip existing <title>, <meta name="description">, and existing og: / twitter: meta tags from head
$html = preg_replace('/<title>.*?<\/title>/is', '', $html);
$html = preg_replace('/<meta\s+name=["\']description["\'].*?>/is', '', $html);
$html = preg_replace('/<meta\s+property=["\']og:[^"\']+["\'].*?>/is', '', $html);
$html = preg_replace('/<meta\s+name=["\']twitter:[^"\']+["\'].*?>/is', '', $html);

// Inject new meta tags right before </head>
$html = str_replace('</head>', $metaBlock . "\n  </head>", $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
