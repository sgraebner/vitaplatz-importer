<?php
// The URL to send the POST request to
$url = 'http://localhost:9004/import.php';

// The JSON data to send in the POST request
$jsonData = '{
  "request_info": {
    "success": true,
    "type": "collection_resultset_completed"
  },
  "result_set": {
    "id": 1,
    "started_at": "2024-09-29T20:09:14.485Z",
    "ended_at": "2024-09-29T20:09:43.929Z",
    "requests_completed": 16,
    "requests_failed": 0,
    "download_links": {
      "json": {
        "all_pages": "https://data.rainforestapi.com/results/29_SEPTEMBER_2024/2009/Collection_Results_12F7E6D6_1_All_Pages.zip",
        "pages": [
          "https://data.rainforestapi.com/results/29_SEPTEMBER_2024/2009/Collection_Results_12F7E6D6_1_Page_1.json"
        ]
      }
    }
  },
  "collection": {
    "id": "12F7E6D6",
    "name": "Akkugeräte"
  }
}';

// Initialize cURL session
$ch = curl_init($url);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, true); // Use POST method
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData); // Set POST data
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($jsonData)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response as a string

// Execute the POST request
$response = curl_exec($ch);

// Check for errors
if ($response === false) {
    echo 'cURL Error: ' . curl_error($ch);
} else {
    // Print the response from the server
    echo 'Server Response: ' . $response;
}

// Close cURL session
curl_close($ch);
?>
