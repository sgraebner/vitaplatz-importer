<?php
// test_webhook.php

$testData = [
    'result_set' => [
        'download_links' => [
            'json' => [
                'pages' => [
                    'https://your-domain.com/test_page_1.json',
                    'https://your-domain.com/test_page_2.json',
                ]
            ]
        ]
    ],
    'collection' => [
        'id' => 'test_collection_id',
    ]
];

$ch = curl_init('http://localhost/import.php'); // Adjust the URL if needed
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo 'Curl error: ' . curl_error($ch) . "\n";
} else {
    echo "HTTP code: $httpCode\n";
    echo "Response: $response\n";
}

curl_close($ch);
