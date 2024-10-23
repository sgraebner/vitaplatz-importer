<?php
declare(strict_types=1);

// Enable strict error reporting
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Increase the maximum execution time if needed
set_time_limit(0);

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

// Send immediate response to the webhook initiator
http_response_code(200);
echo "Webhook received successfully.";

// Flush the output buffers and close the connection to the client
if (function_exists('fastcgi_finish_request')) {
    // Available in PHP-FPM
    session_write_close();
    fastcgi_finish_request();
} else {
    // Fallback for other SAPIs
    ignore_user_abort(true);
    @ob_end_flush();
    @ob_flush();
    @flush();
}

// Now continue processing in the background

// Create the directory path
$directoryPath = __DIR__ . '/processing/' . $collectionId;

// Create the directory if it doesn't exist
if (!is_dir($directoryPath) && !mkdir($directoryPath, 0777, true)) {
    // Log the error
    error_log("Failed to create directory: $directoryPath");
    exit;
}

// Get the list of page URLs
$pageUrls = $data['result_set']['download_links']['json']['pages'] ?? [];

if (empty($pageUrls)) {
    // Log the error
    error_log("No page URLs found in the webhook data.");
    exit;
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

    // Optionally, log success
    error_log("Files downloaded successfully for collection ID: $collectionId.");

} catch (Exception $e) {
    // Log any exceptions during the download process
    error_log("An error occurred: " . $e->getMessage());
    exit;
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
        CURLOPT_TIMEOUT         => 300,
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
