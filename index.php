<?php
// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// System-wide binary paths for Docker environment
define('YT_DLP_PATH', 'yt-dlp');
define('FFMPEG_PATH', 'ffmpeg');
define('DOWNLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'downloads');

if (!is_dir(DOWNLOAD_DIR)) {
    mkdir(DOWNLOAD_DIR, 0755, true);
    file_put_contents(DOWNLOAD_DIR . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n<Files *>\n    Require all granted\n</Files>");
}

// Cleanup files older than 1 hour
$files = glob(DOWNLOAD_DIR . DIRECTORY_SEPARATOR . '*.mp3');
$now = time();
foreach ($files ?: [] as $file) {
    if (is_file($file) && ($now - filemtime($file)) > 3600)
        @unlink($file);
}

// API Routing
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    if ($action === 'info') {
        echo json_encode(getVideoInfo($_POST['url'] ?? ''));
        exit;
    }
    if ($action === 'convert') {
        echo json_encode(convertVideo($_POST['url'] ?? ''));
        exit;
    }
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

// Status check endpoint
header('Content-Type: application/json');
echo json_encode([
    'status' => 'online',
    'message' => 'YouTube to MP3 Converter API is running.'
]);
exit;

function validateYoutubeUrl($url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL))
        return false;
    $parsed = parse_url($url);
    $host = strtolower($parsed['host'] ?? '');
    return in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com']);
}

function getVideoInfo($url)
{
    if (!validateYoutubeUrl($url))
        return ['error' => 'Please enter a valid YouTube URL.'];
    $safeUrl = escapeshellarg($url);
    $ytDlp = escapeshellarg(YT_DLP_PATH);
    $output = [];
    $rc = 0;
    exec("$ytDlp --skip-download --print-json --no-warnings $safeUrl 2>&1", $output, $rc);
    if ($rc !== 0)
        return ['error' => 'Failed to retrieve video details.'];
    $json = json_decode(implode("", $output), true);
    if (!$json)
        return ['error' => 'Failed to parse video details.'];
    return [
        'success' => true,
        'title' => $json['title'] ?? 'Unknown',
        'duration' => isset($json['duration']) ? gmdate("H:i:s", $json['duration']) : 'Unknown',
        'thumbnail' => $json['thumbnail'] ?? '',
        'uploader' => $json['uploader'] ?? 'Unknown',
        'url' => $url
    ];
}

function convertVideo($url)
{
    if (!validateYoutubeUrl($url))
        return ['error' => 'Invalid URL.'];
    $safeUrl = escapeshellarg($url);
    $ytDlp = escapeshellarg(YT_DLP_PATH);
    $ffmpeg = escapeshellarg(FFMPEG_PATH);
    $uid = uniqid('yt_', true);
    $out = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uid;
    $safeOut = escapeshellarg($out);
    $output = [];
    $rc = 0;

    // Convert audio to mp3 using yt-dlp & ffmpeg
    exec("$ytDlp --ffmpeg-location $ffmpeg -f ba -x --audio-format mp3 --audio-quality 0 -o $safeOut $safeUrl 2>&1", $output, $rc);
    if ($rc !== 0)
        return ['error' => 'Conversion failed.'];

    $expected = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uid . '.mp3';
    if (file_exists($expected)) {
        $infoOut = [];
        exec("$ytDlp --skip-download --print-json $safeUrl", $infoOut);
        $info = json_decode(implode("", $infoOut), true);
        $title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $info['title'] ?? 'audio');

        // Dynamically build the absolute download URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $absoluteUrl = $protocol . $host . $uri . '/downloads/' . $uid . '.mp3';

        return [
            'success' => true,
            'downloadUrl' => $absoluteUrl,
            'filename' => $title . '.mp3'
        ];
    }
    return ['error' => 'Converted file not found.'];
}
