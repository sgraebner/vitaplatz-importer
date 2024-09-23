<?php
// webhook.php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Dotenv\Dotenv;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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

// Initialize rate limiters
$storage = new InMemoryStorage();

$rainforestRateLimiter = new RateLimiterFactory([
    'id' => 'rainforest_api_limiter',
    'policy' => 'token_bucket',
    'limit' => 60,
    'rate' => ['interval' => '1 minute'],
], $storage);

$openAiRateLimiter = new RateLimiterFactory([
    'id' => 'openai_api_limiter',
    'policy' => 'token_bucket',
    'limit' => 60,
    'rate' => ['interval' => '1 minute'],
], $storage);

$shopwareRateLimiter = new RateLimiterFactory([
    'id' => 'shopware_api_limiter',
    'policy' => 'token_bucket',
    'limit' => 100,
    'rate' => ['interval' => '1 minute'],
], $storage);

// Function to create a Guzzle client with rate limiting middleware
function createRateLimitedClient($rateLimiterFactory)
{
    $handlerStack = HandlerStack::create();
    $handlerStack->push(Middleware::mapRequest(function ($request) use ($rateLimiterFactory) {
        $limiter = $rateLimiterFactory->create($request->getUri()->getHost());
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            // Sleep until the next token is available
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            if ($retryAfter > 0) {
                sleep($retryAfter);
            }
        }

        return $request;
    }));

    return new Client(['handler' => $handlerStack]);
}

// Initialize clients
$client = createRateLimitedClient($shopwareRateLimiter);
$rainforestClient = createRateLimitedClient($rainforestRateLimiter);
$openAiClient = createRateLimitedClient($openAiRateLimiter);

// Initialize error log
$errorLog = [];

// Function to log errors
function logError($message)
{
    global $errorLog;
    $timestamp = date('Y-m-d H:i:s');
    $errorLog[] = "[$timestamp] $message";
}
 
// Function to save error log to file.
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

    // Process each JSON page sequentially
    foreach ($downloadLinks as $pageUrl) {
        processJsonPage($pageUrl, $webhookData);
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
function processJsonPage($pageUrl, $webhookData)
{
    global $rainforestClient;

    try {
        // Download the JSON page
        $response = $rainforestClient->get($pageUrl);
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

            processProduct($productResult, $webhookData);
        }

    } catch (RequestException $e) {
        logError('HTTP Request Error: ' . $e->getMessage());
    } catch (Exception $e) {
        logError('Processing Error: ' . $e->getMessage());
    }
}

// Function to process a single product
function processProduct($product, $webhookData)
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
        $shopwareProductData = mapProductData($product, $webhookData);

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
function mapProductData($product, $webhookData)
{
    global $customFieldsPrefix, $salesChannelName;

    $mappedData = [];

    // Basic fields
    $mappedData['productNumber'] = $product['asin'] ?? '';
    $mappedData['name'] = $product['title'] ?? 'Unnamed Product';
    $mappedData['description'] = $product['description'] ?? '';
    $mappedData['releaseDate'] = isset($product['first_available']['raw']) ? formatReleaseDate($product['first_available']['raw']) : null;
    $mappedData['manufacturer'] = getManufacturerId($product['brand'] ?? 'Unknown');
    $mappedData['keywords'] = $product['keywords'] ?? '';
    $mappedData['ratingAverage'] = $product['rating'] ?? null;
    $mappedData['ean'] = $product['ean'] ?? null;
    $mappedData['salesChannel'] = getSalesChannelId($salesChannelName);

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

    $mappedData['customFields'] = array_filter($customFields, function($value) {
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
    $mappedData['categories'] = getCategoryIds($webhookData);

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
        $mappedData['variants'] = mapVariants($product['variants'], $mappedData);
    }

    // Reviews
    if (isset($product['top_reviews']) && is_array($product['top_reviews'])) {
        $mappedData['reviews'] = mapReviews($product['top_reviews'], $mappedData['productNumber']);
    }

    // Return the mapped data
    return $mappedData;
}

// Function to standardize units using OpenAI API
function standardizeUnits($value, $type)
{
    global $openAiApiKey, $openAiClient;

    // Prepare the prompt
    $prompt = "Convert the following $type to units compatible with Shopware 6. Provide the result as JSON with keys ";

    if ($type === 'weight') {
        $prompt .= "`weight` in kilograms.";
    } elseif ($type === 'dimensions') {
        $prompt .= "`length`, `width`, and `height` in meters.";
    }

    $prompt .= " Only provide the JSON output without any explanation or additional text.\n\n$value";

    $response = callOpenAiApi($prompt, $openAiClient);

    // Parse the JSON response
    $standardizedData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('Error parsing OpenAI response: ' . json_last_error_msg());
        return null;
    }

    return $standardizedData;
}

// Function to call OpenAI API
function callOpenAiApi($prompt, $client)
{
    global $openAiApiKey;

    try {
        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer $openAiApiKey",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => 100,
                'temperature' => 0,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['choices'][0]['message']['content'] ?? '';

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
function getCategoryIds($webhookData)
{
    global $client, $shopwareApiUrl;

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

// Function to get manufacturer ID
function getManufacturerId($manufacturerName)
{
    global $client, $shopwareApiUrl;
    $token = getShopwareToken();

    try {
        // Check if manufacturer exists
        $response = $client->post("$shopwareApiUrl/api/search/product-manufacturer", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $manufacturerName,
                    ],
                ],
                'includes' => ['id'],
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (!empty($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        } else {
            // Create manufacturer
            $manufacturerId = bin2hex(random_bytes(16));
            $client->post("$shopwareApiUrl/api/product-manufacturer", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'id' => $manufacturerId,
                    'name' => $manufacturerName,
                ],
            ]);

            return $manufacturerId;
        }

    } catch (RequestException $e) {
        logError('Error fetching or creating manufacturer: ' . $e->getMessage());
        return null;
    }
}

// Function to get sales channel ID
function getSalesChannelId($salesChannelName)
{
    global $client, $shopwareApiUrl;
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

// Function to get Shopware authentication token
function getShopwareToken()
{
    global $client, $shopwareApiUrl, $shopwareClientId, $shopwareClientSecret;

    static $token = null;
    static $tokenExpiresAt = null;

    if ($token && $tokenExpiresAt > time()) {
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
        $expiresIn = $data['expires_in'];
        $tokenExpiresAt = time() + $expiresIn - 60; // Renew 60 seconds before expiry

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
                $productData['media'] = array_map(function($mediaId) {
                    return ['mediaId' => $mediaId];
                }, $mediaIds);
            }
        }

        // Remove null values
        $productData = array_filter($productData, function($value) {
            return $value !== null;
        });

        // Generate a unique product ID
        $productData['id'] = bin2hex(random_bytes(16));

        // Create product
        $response = $client->post("$shopwareApiUrl/api/product", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => $productData,
        ]);

        // Handle variants
        if (isset($productData['variants'])) {
            foreach ($productData['variants'] as $variantData) {
                $variantData['parentId'] = $productData['id'];
                createProductVariant($variantData);
            }
        }

        // Product created successfully

    } catch (RequestException $e) {
        logError('Error creating product: ' . $e->getMessage());
    }
}

// Function to create product variant
function createProductVariant($variantData)
{
    global $client, $shopwareApiUrl;

    $token = getShopwareToken();

    try {
        // Remove null values
        $variantData = array_filter($variantData, function($value) {
            return $value !== null;
        });

        // Generate a unique product ID for the variant
        $variantData['id'] = bin2hex(random_bytes(16));

        // Create variant
        $response = $client->post("$shopwareApiUrl/api/product", [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ],
            'json' => $variantData,
        ]);

    } catch (RequestException $e) {
        logError('Error creating product variant: ' . $e->getMessage());
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
            $mediaId = bin2hex(random_bytes(16));
            $client->post("$shopwareApiUrl/api/media", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'id' => $mediaId,
                ],
            ]);

            // Download the image content
            $imageContent = file_get_contents($imageUrl);

            if ($imageContent === false) {
                logError('Error downloading image: ' . $imageUrl);
                continue;
            }

            // Upload the image
            $client->post("$shopwareApiUrl/api/_action/media/$mediaId/upload", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $imageContent,
                        'filename' => basename(parse_url($imageUrl, PHP_URL_PATH)),
                    ],
                ],
            ]);

            $mediaIds[] = $mediaId;

        } catch (RequestException $e) {
            logError('Error uploading image: ' . $e->getMessage());
        }
    }

    return $mediaIds;
}

// Function to map variants
function mapVariants($variants, $parentProductData)
{
    global $customFieldsPrefix;

    $mappedVariants = [];

    foreach ($variants as $variant) {
        $variantData = [];

        // Use the parent product data as a base
        $variantData = $parentProductData;

        // Override fields with variant-specific data
        $variantData['productNumber'] = $variant['asin'] ?? '';
        $variantData['name'] = $variant['text'] ?? $parentProductData['name'];

        // Handle variant options (e.g., size, color)
        // Assuming the variant dimensions are stored in 'dimensions'
        if (isset($variant['dimensions'])) {
            $variantData['customFields'][$customFieldsPrefix . 'variantDimensions'] = $variant['dimensions'];
        }

        // Prices
        if (isset($variant['price']['value'])) {
            $variantData['price'][0]['gross'] = $variant['price']['value'];
            $variantData['price'][0]['net'] = $variant['price']['value'] / 1.19; // Assuming 19% VAT
        }

        // Additional variant-specific custom fields
        $variantData['customFields'][$customFieldsPrefix . 'variantLink'] = $variant['link'] ?? null;
        $variantData['customFields'][$customFieldsPrefix . 'isCurrentProduct'] = $variant['is_current_product'] ?? null;
        $variantData['customFields'][$customFieldsPrefix . 'variantFormat'] = $variant['format'] ?? null;
        $variantData['customFields'][$customFieldsPrefix . 'priceInCart'] = $variant['price_only_available_in_cart'] ?? null;

        // Remove images from variant to avoid duplication
        unset($variantData['images']);

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
        $reviewData['productId'] = getProductIdByNumber($productNumber);

        // Custom fields
        $customFields = [];
        $customFields[$customFieldsPrefix . 'reviewId'] = $review['id'] ?? null;
        $customFields[$customFieldsPrefix . 'verifiedPurchase'] = $review['verified_purchase'] ?? null;
        $customFields[$customFieldsPrefix . 'helpfulVotes'] = $review['helpful_votes'] ?? null;

        $reviewData['customFields'] = array_filter($customFields, function($value) {
            return $value !== null;
        });

        $mappedReviews[] = $reviewData;
    }

    return $mappedReviews;
}

// Function to get product ID by product number
function getProductIdByNumber($productNumber)
{
    global $client, $shopwareApiUrl;

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

        if (!empty($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }

        return null;

    } catch (RequestException $e) {
        logError('Error fetching product ID: ' . $e->getMessage());
        return null;
    }
}

// Function to create reviews in Shopware
function createReviews($reviews)
{
    global $client, $shopwareApiUrl;

    $token = getShopwareToken();

    foreach ($reviews as $reviewData) {
        try {
            $client->post("$shopwareApiUrl/api/product-review", [
                'headers' => [
                    'Authorization' => "Bearer $token",
                    'Content-Type' => 'application/json',
                ],
                'json' => $reviewData,
            ]);

        } catch (RequestException $e) {
            logError('Error creating review: ' . $e->getMessage());
        }
    }
}
