<?php
// webhook.php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration
$shopwareApiUrl = rtrim($_ENV['SHOPWARE_API_URL'], '/');
$shopwareClientId = $_ENV['SHOPWARE_CLIENT_ID'];
$shopwareClientSecret = $_ENV['SHOPWARE_CLIENT_SECRET'];
$salesChannelName = $_ENV['SALES_CHANNEL_NAME'];
$customFieldsPrefix = $_ENV['CUSTOM_FIELDS_PREFIX'];
$openAiApiKey = $_ENV['OPENAI_API_KEY'];

// Initialize HTTP client
$client = new Client();

// Initialize error log
$errorLog = [];

// Function to log errors
function logError($message)
{
    global $errorLog;
    $timestamp = date('Y-m-d H:i:s');
    $errorLog[] = "[$timestamp] $message";
}

// Function to save error log to file
function saveErrorLog()
{
    global $errorLog;
    $logContent = implode(PHP_EOL, $errorLog);
    file_put_contents('error_log.txt', $logContent);
}

// Handle incoming webhook request
try {
    // Ensure the request is a POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // Get the POST body
    $requestBody = file_get_contents('php://input');
    $webhookData = json_decode($requestBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in webhook payload');
    }

    // Extract download links
    $downloadLinks = $webhookData['result_set']['download_links']['json']['pages'] ?? [];

    if (empty($downloadLinks)) {
        throw new Exception('No download links found in webhook payload');
    }

    // Set webhook data globally for access in other functions
    $GLOBALS['webhookData'] = $webhookData;

    // Process each JSON page sequentially
    foreach ($downloadLinks as $pageUrl) {
        processJsonPage($pageUrl);
    }

    // Save error log after processing
    saveErrorLog();

    // Respond to the webhook
    http_response_code(200);
    echo 'Webhook processed successfully';

} catch (Exception $e) {
    logError($e->getMessage());
    saveErrorLog();
    http_response_code(500);
    echo 'An error occurred: ' . $e->getMessage();
    exit;
}

// Function to process a single JSON page
function processJsonPage($pageUrl)
{
    global $client, $shopwareApiUrl, $shopwareClientId, $shopwareClientSecret, $salesChannelName, $customFieldsPrefix, $openAiApiKey;

    try {
        // Download the JSON page
        $response = $client->get($pageUrl);
        $jsonData = json_decode($response->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in downloaded page');
        }

        // Process each product in the JSON data
        foreach ($jsonData as $productData) {
            if (!$productData['success']) {
                continue; // Skip unsuccessful entries
            }

            $productResult = $productData['result']['product'] ?? null;

            if (!$productResult) {
                continue; // Skip if product data is missing
            }

            processProduct($productResult);
        }

    } catch (RequestException $e) {
        logError('HTTP Request Error: ' . $e->getMessage());
    } catch (Exception $e) {
        logError('Processing Error: ' . $e->getMessage());
    }
}

// Function to process a single product
function processProduct($product)
{
    global $client, $shopwareApiUrl, $salesChannelName, $customFieldsPrefix;

    try {
        // Check if product already exists in Shopware
        $productNumber = $product['asin'] ?? null;

        if (!$productNumber) {
            throw new Exception('Product ASIN is missing');
        }

        if (productExistsInShopware($productNumber)) {
            return; // Skip existing products
        }

        // Map JSON fields to Shopware fields
        $shopwareProductData = mapProductData($product);

        // Create the product in Shopware
        createProductInShopware($shopwareProductData);

    } catch (Exception $e) {
        logError('Product Processing Error: ' . $e->getMessage());
    }
}

// Function to check if a product exists in Shopware
function productExistsInShopware($productNumber)
{
    global $client, $shopwareApiUrl;

    // Get authentication token
    $token = getShopwareToken();

    try {
        $response = $client->post("$shopwareApiUrl/api/search/product", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'productNumber',
                        'value' => $productNumber,
                    ],
                ],
                'includes' => ['id'],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['total'] > 0;

    } catch (RequestException $e) {
        logError('Error checking product existence: ' . $e->getMessage());
        return false;
    }
}

// Function to map product data from JSON to Shopware format
function mapProductData($product)
{
    global $customFieldsPrefix;

    $mappedData = [];

    // Basic fields
    $mappedData['productNumber'] = $product['asin'] ?? '';
    $mappedData['name'] = $product['title'] ?? 'Unnamed Product';
    $mappedData['description'] = $product['description'] ?? '';
    $mappedData['releaseDate'] = isset($product['first_available']['raw']) ? formatReleaseDate($product['first_available']['raw']) : null;
    $mappedData['manufacturer'] = $product['brand'] ?? 'Unknown';
    $mappedData['keywords'] = $product['keywords'] ?? '';
    $mappedData['ratingAverage'] = $product['rating'] ?? null;
    $mappedData['ean'] = $product['ean'] ?? null;

    // Custom fields
    $customFields = [];
    $customFields[$customFieldsPrefix . 'parentAsin'] = $product['parent_asin'] ?? null;
    $customFields[$customFieldsPrefix . 'productLink'] = $product['link'] ?? null;
    $customFields[$customFieldsPrefix . 'shippingWeight'] = $product['shipping_weight'] ?? null;
    $customFields[$customFieldsPrefix . 'deliveryMessage'] = $product['delivery_message'] ?? null;
    $customFields[$customFieldsPrefix . 'subTitle'] = $product['sub_title']['text'] ?? null;
    $customFields[$customFieldsPrefix . 'ratingsTotal'] = $product['ratings_total'] ?? null;
    $customFields[$customFieldsPrefix . 'reviewsTotal'] = $product['reviews_total'] ?? null;
    $customFields[$customFieldsPrefix . 'isBundle'] = $product['is_bundle'] ?? null;
    // ... Add other custom fields as per mapping table

    $mappedData['customFields'] = array_filter($customFields, function ($value) {
        return $value !== null;
    });

    // Dimensions and weight (standardize via OpenAI API)
    $mappedData['weight'] = isset($product['weight']) ? standardizeUnits($product['weight'], 'weight') : null;
    $dimensions = isset($product['dimensions']) ? standardizeUnits($product['dimensions'], 'dimensions') : null;

    if ($dimensions) {
        $mappedData['length'] = $dimensions['length'] ?? null;
        $mappedData['width'] = $dimensions['width'] ?? null;
        $mappedData['height'] = $dimensions['height'] ?? null;
    }

    // Categories
    $mappedData['categories'] = getCategoryIds();

    // Prices
    if (isset($product['buybox_winner']['price']['value'])) {
        $mappedData['price'] = [
            [
                'currencyId' => getCurrencyId('USD'),
                'gross' => $product['buybox_winner']['price']['value'],
                'net' => $product['buybox_winner']['price']['value'] / 1.19, // Assuming 19% VAT
                'linked' => false,
            ]
        ];
    }

    // Stock and availability
    $availabilityType = $product['buybox_winner']['availability']['type'] ?? 'out_of_stock';
    $mappedData['stock'] = $availabilityType === 'in_stock' ? 100 : 0;
    $mappedData['active'] = $availabilityType === 'in_stock';

    // Sales channel assignment
    $mappedData['visibilities'] = [
        [
            'salesChannelId' => getSalesChannelId(),
            'visibility' => 30, // Visibility all
        ],
    ];

    // Images
    $images = [];
    if (isset($product['main_image']['link'])) {
        $images[] = $product['main_image']['link'];
    }
    if (isset($product['images']) && is_array($product['images'])) {
        foreach ($product['images'] as $image) {
            if (isset($image['link'])) {
                $images[] = $image['link'];
            }
        }
    }
    $mappedData['images'] = $images;

    // Variants
    if (isset($product['variants']) && is_array($product['variants'])) {
        $mappedData['configuratorSettings'] = mapVariants($product['variants']);
    }

    // Reviews
    if (isset($product['top_reviews']) && is_array($product['top_reviews'])) {
        $mappedData['productReviews'] = mapReviews($product['top_reviews'], $mappedData['productNumber']);
    }

    // Return the mapped data
    return $mappedData;
}

// Function to standardize units using OpenAI API
function standardizeUnits($value, $type)
{
    global $openAiApiKey;

    // Prepare the prompt
    $prompt = "Convert the following $type to standard units compatible with Shopware 6, and provide the result as JSON only without explanation:\n\n$value";

    $response = callOpenAiApi($prompt);

    // Parse the JSON response
    $standardizedData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('Error parsing OpenAI response: ' . json_last_error_msg());
        return null;
    }

    return $standardizedData;
}

// Function to call OpenAI API
function callOpenAiApi($prompt)
{
    global $client, $openAiApiKey;

    try {
        $response = $client->post('https://api.openai.com/v1/completions', [
            'headers' => [
                'Authorization' => "Bearer $openAiApiKey",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'text-davinci-003',
                'prompt' => $prompt,
                'max_tokens' => 150,
                'temperature' => 0,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['choices'][0]['text'] ?? '';

    } catch (RequestException $e) {
        logError('OpenAI API Error: ' . $e->getMessage());
        return '';
    }
}

// Function to format release date
function formatReleaseDate($dateString)
{
    $date = date_create_from_format('F j, Y', $dateString);
    if ($date) {
        return $date->format('Y-m-d H:i:s');
    }
    return null;
}

// Function to get category IDs based on collection name
function getCategoryIds()
{
    global $client, $shopwareApiUrl, $webhookData;

    $categoryName = $webhookData['collection']['name'] ?? 'Default Category';
    $token = getShopwareToken();

    try {
        $response = $client->post("$shopwareApiUrl/api/search/category", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $categoryName,
                    ],
                ],
                'includes' => ['id'],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        $categoryIds = [];

        if (!empty($data['data'])) {
            foreach ($data['data'] as $category) {
                $categoryIds[] = ['id' => $category['id']];
            }
        }

        return $categoryIds;

    } catch (RequestException $e) {
        logError('Error fetching category IDs: ' . $e->getMessage());
        return [];
    }
}

// Function to get sales channel ID
function getSalesChannelId()
{
    global $client, $shopwareApiUrl, $salesChannelName;

    $token = getShopwareToken();

    try {
        $response = $client->post("$shopwareApiUrl/api/search/sales-channel", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $salesChannelName,
                    ],
                ],
                'includes' => ['id'],
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (!empty($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }

        return null;

    } catch (RequestException $e) {
        logError('Error fetching sales channel ID: ' . $e->getMessage());
        return null;
    }
}

// Function to get currency ID
function getCurrencyId($isoCode)
{
    global $client, $shopwareApiUrl;

    $token = getShopwareToken();

    try {
        $response = $client->post("$shopwareApiUrl/api/search/currency", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'isoCode',
                        'value' => $isoCode,
                    ],
                ],
                'includes' => ['id'],
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (!empty($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }

        return null;

    } catch (RequestException $e) {
        logError('Error fetching currency ID: ' . $e->getMessage());
        return null;
    }
}

// Function to get Shopware authentication token
function getShopwareToken()
{
    global $client, $shopwareApiUrl, $shopwareClientId, $shopwareClientSecret;

    static $token = null;
    static $tokenExpiresAt = null;

    // Check if token is still valid
    if ($token && $tokenExpiresAt && $tokenExpiresAt > time()) {
        return $token;
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/oauth/token", [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $shopwareClientId,
                'client_secret' => $shopwareClientSecret,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        $token = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 0;
        $tokenExpiresAt = time() + $expiresIn - 60; // Subtract 60 seconds as a buffer

        return $token;

    } catch (RequestException $e) {
        logError('Error obtaining Shopware token: ' . $e->getMessage());
        return null;
    }
}

// Function to create product in Shopware
function createProductInShopware($productData)
{
    global $client, $shopwareApiUrl;

    $token = getShopwareToken();

    try {
        // Upload images and get media IDs
        if (!empty($productData['images'])) {
            $mediaIds = uploadImages($productData['images'], $token);
            unset($productData['images']);

            // Set cover image
            if (!empty($mediaIds)) {
                $productData['cover'] = ['mediaId' => $mediaIds[0]];
                $productData['media'] = array_map(function ($mediaId) {
                    return ['mediaId' => $mediaId];
                }, $mediaIds);
            }
        }

        // Remove null values
        $productData = array_filter($productData, function ($value) {
            return $value !== null;
        });

        // Generate a unique ID for the product
        $productId = uuid_create(UUID_TYPE_RANDOM);
        $productData['id'] = $productId;

        // Create product
        $response = $client->post("$shopwareApiUrl/api/product", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => $productData,
        ]);

        $data = json_decode($response->getBody(), true);
        // Product created successfully

    } catch (RequestException $e) {
        logError('Error creating product: ' . $e->getMessage());
    }
}

// Function to upload images to Shopware
function uploadImages($imageUrls, $token)
{
    global $client, $shopwareApiUrl;
    $mediaIds = [];

    foreach ($imageUrls as $imageUrl) {
        try {
            // Create media entity
            $mediaId = uuid_create(UUID_TYPE_RANDOM);

            // Create media without a file first
            $client->post("$shopwareApiUrl/api/media", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'id' => $mediaId,
                ],
            ]);

            // Download image content
            $imageResponse = $client->get($imageUrl);
            $imageContent = $imageResponse->getBody()->getContents();

            // Upload the image
            $client->post("$shopwareApiUrl/api/_action/media/$mediaId/upload", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'form-data; name="file"; filename="' . basename($imageUrl) . '"',
                ],
                'body' => $imageContent,
            ]);

            $mediaIds[] = $mediaId;

        } catch (RequestException $e) {
            logError('Error uploading image: ' . $e->getMessage());
        }
    }

    return $mediaIds;
}

// Function to map variants
function mapVariants($variants)
{
    global $customFieldsPrefix;

    $mappedVariants = [];

    foreach ($variants as $variant) {
        $variantData = [];

        // Options (e.g., size, color)
        $options = [];
        if (isset($variant['dimensions']) && is_array($variant['dimensions'])) {
            foreach ($variant['dimensions'] as $dimension) {
                if (isset($dimension['name'], $dimension['value'])) {
                    $options[] = [
                        'group' => $dimension['name'],
                        'option' => $dimension['value'],
                    ];
                }
            }
        }

        // Custom fields
        $customFields = [];
        $customFields[$customFieldsPrefix . 'variantLink'] = $variant['link'] ?? null;
        $customFields[$customFieldsPrefix . 'isCurrentProduct'] = $variant['is_current_product'] ?? null;
        $customFields[$customFieldsPrefix . 'variantFormat'] = $variant['format'] ?? null;
        $customFields[$customFieldsPrefix . 'priceInCart'] = $variant['price_only_available_in_cart'] ?? null;

        $variantData['options'] = $options;
        $variantData['customFields'] = array_filter($customFields, function ($value) {
            return $value !== null;
        });

        $mappedVariants[] = $variantData;
    }

    return $mappedVariants;
}

// Function to map reviews
function mapReviews($reviews, $productNumber)
{
    global $customFieldsPrefix;

    $mappedReviews = [];

    foreach ($reviews as $review) {
        $reviewData = [];

        $reviewData['title'] = $review['title'] ?? 'No Title';
        $reviewData['content'] = $review['body'] ?? '';
        $reviewData['points'] = $review['rating'] ?? 0;
        $reviewData['customerName'] = $review['profile']['name'] ?? 'Anonymous';
        $reviewData['createdAt'] = isset($review['date']['utc']) ? date('Y-m-d H:i:s', strtotime($review['date']['utc'])) : date('Y-m-d H:i:s');
        $reviewData['status'] = true;
        $reviewData['productId'] = $productNumber;

        // Custom fields
        $customFields = [];
        $customFields[$customFieldsPrefix . 'reviewId'] = $review['id'] ?? null;
        $customFields[$customFieldsPrefix . 'verifiedPurchase'] = $review['verified_purchase'] ?? null;
        $customFields[$customFieldsPrefix . 'helpfulVotes'] = $review['helpful_votes'] ?? null;

        $reviewData['customFields'] = array_filter($customFields, function ($value) {
            return $value !== null;
        });

        $mappedReviews[] = $reviewData;
    }

    return $mappedReviews;
}
