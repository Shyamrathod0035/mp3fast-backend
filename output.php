<?php
/**
 * Asynchronous / Synchronous API Endpoint for YouTube to MP3 Downloader
 * Used by external workflows (like n8n, Zapier, etc.)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
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

// Auto-cleanup files older than 1 hour
cleanOldDownloads(3600);

$url = $_POST['url'] ?? '';

if (empty($url)) {
    $url = $_GET['url'] ?? '';
}

if (empty($url)) {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    $url = $input['url'] ?? '';
}

if (empty($url)) {
    echo json_encode([
        'status' => 'failed',
        'error' => 'No URL parameter provided.'
    ]);
    exit;
}

if (!validateYoutubeUrl($url)) {
    echo json_encode([
        'status' => 'failed',
        'error' => 'Invalid YouTube URL.'
    ]);
    exit;
}

$result = convertVideoToMp3($url);

if (isset($result['error'])) {
    echo json_encode([
        'status' => 'failed',
        'error' => $result['error']
    ]);
} else {
    echo json_encode([
        'status' => 'done',
        'url' => $result['url'] ?? $result['downloadUrl'],
        'filename' => $result['filename']
    ]);
}

function validateYoutubeUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parsed = parse_url($url);
    $host = strtolower($parsed['host'] ?? '');
    return in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com']);
}

function cleanOldDownloads($maxAgeSeconds) {
    if (!is_dir(DOWNLOAD_DIR)) return;
    $files = glob(DOWNLOAD_DIR . DIRECTORY_SEPARATOR . '*.mp3');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
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
                
                if (downloadFileFromUrl($cobaltUrl, $localFilePath)) {
                    $rawTitle = $json['filename'] ?? 'audio';
                    $cleanTitle = preg_replace('/\.mp3$/i', '', $rawTitle);
                    $title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $cleanTitle);

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                    $absoluteUrl = $protocol . $host . $uri . '/downloads/' . $uid . '.mp3';

                    return [
                        'success' => true,
                        'url' => $absoluteUrl,
                        'filename' => $title . '.mp3'
                    ];
                }
            }
        }
    }

    return ['error' => 'All download methods failed.'];
}

function convertVideoToMp3($url) {
    $safeUrl = escapeshellarg($url);
    $ytDlp = escapeshellarg(YT_DLP_PATH);
    $ffmpeg = escapeshellarg(FFMPEG_PATH);
    $cookiesFile = __DIR__ . DIRECTORY_SEPARATOR . 'cookies.txt';
    $cookiesParam = file_exists($cookiesFile) ? ' --cookies ' . escapeshellarg($cookiesFile) : '';
    
    $uniqueId = uniqid('yt_', true);
    $outputTemplate = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uniqueId;
    $safeOutputTemplate = escapeshellarg($outputTemplate);
    
    $command = "$ytDlp$cookiesParam --ffmpeg-location $ffmpeg -f ba -x --audio-format mp3 --audio-quality 0 -o $safeOutputTemplate $safeUrl 2>&1";
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        return convertVideoViaApi($url, $uniqueId);
    }
    
    $expectedFile = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uniqueId . '.mp3';
    
    if (file_exists($expectedFile)) {
        $infoCommand = "$ytDlp$cookiesParam --skip-download --print-json $safeUrl";
        $infoOutput = [];
        exec($infoCommand, $infoOutput);
        $infoData = json_decode(implode("", $infoOutput), true);
        $title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $infoData['title'] ?? 'audio');
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $absoluteUrl = $protocol . $host . $uri . '/downloads/' . $uniqueId . '.mp3';
        
        return [
            'url' => $absoluteUrl,
            'filename' => $title . '.mp3'
        ];
    }
    
    return convertVideoViaApi($url, $uniqueId);
}
