<?php
// The URL to which the POST request will be sent (your local server endpoint)
$url = "http://localhost:8000/webhook.php";

// JSON data you want to send in the POST request
$data = [
    "request_info" => [
        "success" => true,
        "type" => "collection_resultset_completed"
    ],
    "result_set" => [
        "id" => 2,
        "started_at" => "2024-09-23T09:08:44.199Z",
        "ended_at" => "2024-09-23T09:09:17.832Z",
        "requests_completed" => 53,
        "requests_failed" => 0,
        "download_links" => [
            "json" => [
                "all_pages" => "https://data.rainforestapi.com/results/23_SEPTEMBER_2024/0908/Collection_Results_67167BF1_2_All_Pages.zip",
                "pages" => [
                    "https://data.rainforestapi.com/results/23_SEPTEMBER_2024/0908/Collection_Results_67167BF1_2_Page_1.json"
                ]
            ]
        ]
    ],
    "collection" => [
        "id" => "67167BF1",
        "name" => "Magnesium"
    ]
];

// Initialize cURL session
$ch = curl_init($url);

// Convert PHP array to JSON
$jsonData = json_encode($data);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, true);                        // Perform a POST request
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);               // Return the response
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',                        // Set content type to JSON
    'Content-Length: ' . strlen($jsonData)                   // Set content length
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);              // Attach the JSON data

// Execute the request and store the response
$response = curl_exec($ch);

// Check for any errors in the request
if ($response === false) {
    $error = curl_error($ch);
    echo "cURL Error: $error";
} else {
    echo "Response from server: $response";
}

// Close cURL session
curl_close($ch);
?>
