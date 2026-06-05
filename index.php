<?php
// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// System-wide binary paths for Docker environment
$ytDlpPath = 'yt-dlp';
if (file_exists('/usr/local/bin/yt-dlp')) {
    $ytDlpPath = '/usr/local/bin/yt-dlp';
} elseif (file_exists('/usr/bin/yt-dlp')) {
    $ytDlpPath = '/usr/bin/yt-dlp';
}

$ffmpegPath = 'ffmpeg';
if (file_exists('/usr/bin/ffmpeg')) {
    $ffmpegPath = '/usr/bin/ffmpeg';
} elseif (file_exists('/usr/local/bin/ffmpeg')) {
    $ffmpegPath = '/usr/local/bin/ffmpeg';
}

define('YT_DLP_PATH', $ytDlpPath);
define('FFMPEG_PATH', $ffmpegPath);
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
$cookiesFile = __DIR__ . DIRECTORY_SEPARATOR . 'cookies.txt';
echo json_encode([
    'status' => 'online',
    'message' => 'YouTube to MP3 Converter API is running.',
    'yt_dlp_path' => YT_DLP_PATH,
    'ffmpeg_path' => FFMPEG_PATH,
    'cookies_enabled' => file_exists($cookiesFile)
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

function downloadFileFromUrl($url, $destinationPath) {
    $ch = curl_init($url);
    $fp = fopen($destinationPath, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $success = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $success && file_exists($destinationPath) && filesize($destinationPath) > 0;
}

function getVideoInfoViaApi($url)
{
    $apiUrl = 'https://noembed.com/embed?url=' . urlencode($url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!$json || isset($json['error'])) {
        return ['error' => 'Failed to retrieve video details from API.'];
    }

    return [
        'success' => true,
        'title' => $json['title'] ?? 'Unknown Video',
        'duration' => 'Track',
        'thumbnail' => $json['thumbnail_url'] ?? '',
        'uploader' => $json['author_name'] ?? 'Unknown Uploader',
        'url' => $url
    ];
}

function convertVideoViaApi($url, $uid)
{
    $instances = [
        'https://api.cobalt.tools/',
        'https://cobalt.wren.moe/',
        'https://co.wuk.sh/'
    ];

    $payload = json_encode([
        'url' => $url,
        'downloadMode' => 'audio',
        'audioFormat' => 'mp3',
        'filenameStyle' => 'pretty'
    ]);

    foreach ($instances as $instance) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $instance);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $json = json_decode($response, true);
            if (isset($json['url'])) {
                $cobaltUrl = $json['url'];
                $localFilePath = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uid . '.mp3';
                
                // Download file from Cobalt directly to our local server
                if (downloadFileFromUrl($cobaltUrl, $localFilePath)) {
                    $rawTitle = $json['filename'] ?? 'audio';
                    // Strip file extensions if they got appended
                    $cleanTitle = preg_replace('/\.mp3$/i', '', $rawTitle);
                    $title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $cleanTitle);

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
            }
        }
    }

    return ['error' => 'All download methods failed. Please try again later.'];
}

function getVideoInfo($url)
{
    if (!validateYoutubeUrl($url))
        return ['error' => 'Please enter a valid YouTube URL.'];
    
    $safeUrl = escapeshellarg($url);
    $ytDlp = escapeshellarg(YT_DLP_PATH);
    $cookiesFile = __DIR__ . DIRECTORY_SEPARATOR . 'cookies.txt';
    $cookiesParam = file_exists($cookiesFile) ? ' --cookies ' . escapeshellarg($cookiesFile) : '';
    
    $output = [];
    $rc = 0;
    
    exec("$ytDlp$cookiesParam --skip-download --print-json --no-warnings $safeUrl 2>&1", $output, $rc);
    if ($rc !== 0) {
        return getVideoInfoViaApi($url);
    }
    
    $json = json_decode(implode("", $output), true);
    if (!$json) {
        return getVideoInfoViaApi($url);
    }
    
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
    $cookiesFile = __DIR__ . DIRECTORY_SEPARATOR . 'cookies.txt';
    $cookiesParam = file_exists($cookiesFile) ? ' --cookies ' . escapeshellarg($cookiesFile) : '';
    
    $uid = uniqid('yt_', true);
    $out = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uid;
    $safeOut = escapeshellarg($out);
    $output = [];
    $rc = 0;

    exec("$ytDlp$cookiesParam --ffmpeg-location $ffmpeg -f ba -x --audio-format mp3 --audio-quality 0 -o $safeOut $safeUrl 2>&1", $output, $rc);
    if ($rc !== 0) {
        return convertVideoViaApi($url, $uid);
    }

    $expected = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uid . '.mp3';
    if (file_exists($expected)) {
        $infoOut = [];
        exec("$ytDlp$cookiesParam --skip-download --print-json $safeUrl", $infoOut);
        $info = json_decode(implode("", $infoOut), true);
        $title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $info['title'] ?? 'audio');

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
    
    return convertVideoViaApi($url, $uid);
}
