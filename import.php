<?php
declare(strict_types=1);

// Enable strict error reporting
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Get the raw POST data
$rawPostData = file_get_contents('php://input');

// Decode the JSON data with error handling
try {
    $data = json_decode($rawPostData, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400); // Bad Request
    exit("Invalid JSON data received: " . $e->getMessage());
}

// Extract the collection ID
$collectionId = $data['collection']['id'] ?? null;

if (!$collectionId) {
    http_response_code(400); // Bad Request
    exit("Collection ID is missing in the webhook data.");
}

// Create the directory path
$directoryPath = __DIR__ . '/processing/' . $collectionId;

// Create the directory if it doesn't exist
if (!is_dir($directoryPath) && !mkdir($directoryPath, 0777, true)) {
    http_response_code(500); // Internal Server Error
    exit("Failed to create directory: $directoryPath");
}

// Get the list of page URLs
$pageUrls = $data['result_set']['download_links']['json']['pages'] ?? [];

if (empty($pageUrls)) {
    http_response_code(400); // Bad Request
    exit("No page URLs found in the webhook data.");
}

try {
    foreach ($pageUrls as $pageUrl) {
        // Extract the filename from the URL
        $filename = basename(parse_url($pageUrl, PHP_URL_PATH));

        // Set the full path where the file will be saved
        $savePath = $directoryPath . '/' . $filename;

        // Download and save the file
        downloadFile($pageUrl, $savePath);
    }

    http_response_code(200); // OK
    echo "Files downloaded successfully.";

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    exit("An error occurred: " . $e->getMessage());
}

/**
 * Downloads a file from a URL and saves it to a specified path.
 *
 * @param string $url      The URL to download.
 * @param string $savePath The local path where the file will be saved.
 *
 * @throws Exception If the download fails.
 */
function downloadFile(string $url, string $savePath): void
{
    $fp = fopen($savePath, 'w');

    if ($fp === false) {
        throw new Exception("Failed to open file for writing: $savePath");
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_FAILONERROR     => true,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 60,
        CURLOPT_USERAGENT       => 'WebhookDownloader/1.0',
    ]);

    if (curl_exec($ch) === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        unlink($savePath); // Remove incomplete file
        throw new Exception("Failed to download $url: $error");
    }

    curl_close($ch);
    fclose($fp);
}
