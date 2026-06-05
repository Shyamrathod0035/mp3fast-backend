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
define('YT_DLP_PATH', 'yt-dlp');
define('FFMPEG_PATH', 'ffmpeg');
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
        'url' => $result['url'],
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

function convertVideoToMp3($url) {
    $safeUrl = escapeshellarg($url);
    $ytDlp = escapeshellarg(YT_DLP_PATH);
    $ffmpeg = escapeshellarg(FFMPEG_PATH);
    
    $uniqueId = uniqid('yt_', true);
    $outputTemplate = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uniqueId;
    $safeOutputTemplate = escapeshellarg($outputTemplate);
    
    $command = "$ytDlp --ffmpeg-location $ffmpeg -f ba -x --audio-format mp3 --audio-quality 0 -o $safeOutputTemplate $safeUrl 2>&1";
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        return [
            'error' => 'Conversion process failed. Output: ' . implode("\n", $output)
        ];
    }
    
    $expectedFile = DOWNLOAD_DIR . DIRECTORY_SEPARATOR . $uniqueId . '.mp3';
    
    if (file_exists($expectedFile)) {
        $infoCommand = "$ytDlp --skip-download --print-json $safeUrl";
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
    
    return [
        'error' => 'Output file was not found on the server.'
    ];
}
