<?php
// webhook.php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration
$shopwareApiUrl = rtrim($_ENV['SHOPWARE_API_URL'] ?? '', '/');
$shopwareClientId = $_ENV['SHOPWARE_CLIENT_ID'] ?? '';
$shopwareClientSecret = $_ENV['SHOPWARE_CLIENT_SECRET'] ?? '';
$salesChannelName = $_ENV['SALES_CHANNEL_NAME'] ?? '';
$customFieldsPrefix = $_ENV['CUSTOM_FIELDS_PREFIX'] ?? '';
$openAiApiKey = $_ENV['OPENAI_API_KEY'] ?? '';
$mediaFolderName = $_ENV['MEDIA_FOLDER_NAME'] ?? 'Default Media Folder';

// Validate environment variables
$requiredEnvVars = [
    'SHOPWARE_API_URL',
    'SHOPWARE_CLIENT_ID',
    'SHOPWARE_CLIENT_SECRET',
    'SALES_CHANNEL_NAME',
    'CUSTOM_FIELDS_PREFIX',
    'OPENAI_API_KEY',
    'MEDIA_FOLDER_NAME',
];

foreach ($requiredEnvVars as $envVar) {
    if (empty($_ENV[$envVar])) {
        http_response_code(500);
        echo "Environment variable $envVar is not set.";
        exit;
    }
}

// Initialize HTTP client with timeouts and retries
$client = new Client([
    'timeout' => 30,
    'connect_timeout' => 10,
    'retry_on_timeout' => true,
]);

// Initialize error log file
$errorLogFile = 'error_log.txt';
// Overwrite the error log file at the beginning
file_put_contents($errorLogFile, '');

// Initialize general log file
$generalLogFile = 'general_log.txt';
// Overwrite the general log file at the beginning
file_put_contents($generalLogFile, '');

// Common Guzzle headers
$guzzleHeaders = [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
];

// Function to log errors immediately
function logError(string $message): void
{
    global $errorLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] ERROR: $message" . PHP_EOL;
    // Write the error message to the log file
    file_put_contents($errorLogFile, $logMessage, FILE_APPEND);
}

// Function to log general messages
function logMessage(string $message): void
{
    global $generalLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] INFO: $message" . PHP_EOL;
    // Write the message to the log file
    file_put_contents($generalLogFile, $logMessage, FILE_APPEND);
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
    $webhookData = json_decode($requestBody, true, flags: JSON_THROW_ON_ERROR);

    // Log the received webhook data
    logMessage('Received webhook data: ' . $requestBody);

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

    // Respond to the webhook
    http_response_code(200);
    echo 'Webhook processed successfully';

} catch (Exception $e) {
    logError($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo 'An error occurred: ' . $e->getMessage();
    exit;
}

// Function to process a single JSON page
function processJsonPage(string $pageUrl): void
{
    global $client, $GLOBALS, $guzzleHeaders;

    try {
        // Log the processing of the page
        logMessage("Processing JSON page: $pageUrl");

        // Download the JSON page
        $response = $client->get($pageUrl);
        $jsonData = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        // Retrieve and store the category ID (only once)
        if (!isset($GLOBALS['categoryId'])) {
            $categoryId = getCategoryId();
            $GLOBALS['categoryId'] = $categoryId;
            logMessage("Category ID retrieved: $categoryId");
        }

        // Process each product in the JSON data
        foreach ($jsonData as $productData) {
            if (!($productData['success'] ?? false)) {
                continue; // Skip unsuccessful entries
            }

            $productResult = $productData['result']['product'] ?? null;

            if (!$productResult) {
                continue; // Skip if product data is missing
            }

            processProduct($productResult);
        }

    } catch (RequestException $e) {
        logError('HTTP Request Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    } catch (Exception $e) {
        logError('Processing Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Function to process a single product
function processProduct(array $product): void
{
    try {
        // Log the processing of the product
        logMessage("Processing product with ASIN: " . ($product['asin'] ?? 'Unknown'));

        // Check if product already exists in Shopware
        $productNumber = $product['asin'] ?? null;

        if (!$productNumber) {
            throw new Exception('Product ASIN is missing');
        }

        if (productExistsInShopware($productNumber)) {
            logMessage("Product with ASIN $productNumber already exists in Shopware.");
            return; // Skip existing products
        }

        // Map JSON fields to Shopware fields
        $shopwareProductData = mapProductData($product);

        // Create the product in Shopware
        createProductInShopware($shopwareProductData);

    } catch (Exception $e) {
        logError('Product Processing Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Function to check if a product exists in Shopware
function productExistsInShopware(string $productNumber): bool
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    // Get authentication token
    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/search/product", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'productNumber',
                        'value' => $productNumber,
                    ],
                ],
                'includes' => [
                    'product' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return ($data['total'] ?? 0) > 0;

    } catch (RequestException $e) {
        logError('Error checking product existence: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return false;
    }
}

// Function to map product data from JSON to Shopware format
function mapProductData(array $product): array
{
    global $customFieldsPrefix, $GLOBALS;

    $mappedData = [];

    // Basic fields
    $mappedData['productNumber'] = $product['asin'] ?? '';
    $mappedData['name'] = $product['title'] ?? 'Unnamed Product';
    $mappedData['description'] = $product['description'] ?? '';
    $mappedData['releaseDate'] = isset($product['first_available']['raw']) ? formatReleaseDate($product['first_available']['raw']) : null;
    $mappedData['keywords'] = $product['keywords'] ?? '';
    // Remove 'ratingAverage' as it's write-protected
    // $mappedData['ratingAverage'] = $product['rating'] ?? null;
    $mappedData['ean'] = $product['ean'] ?? null;

    // Get or create manufacturer and set manufacturerId
    $manufacturerName = $product['brand'] ?? 'Unknown';
    $manufacturerId = getManufacturerId($manufacturerName);
    $mappedData['manufacturerId'] = $manufacturerId;

    // Provide taxId
    $mappedData['taxId'] = getDefaultTaxId();

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

    $mappedData['customFields'] = array_filter($customFields, fn($value) => $value !== null);

    // Dimensions and weight (standardize via OpenAI API)
    $weightValue = isset($product['weight']) ? standardizeUnits($product['weight'], 'weight') : null;
    $mappedData['weight'] = is_numeric($weightValue) ? (float)$weightValue : null;

    $dimensions = isset($product['dimensions']) ? standardizeUnits($product['dimensions'], 'dimensions') : null;

    if ($dimensions) {
        $mappedData['length'] = $dimensions['length'] ?? null;
        $mappedData['width'] = $dimensions['width'] ?? null;
        $mappedData['height'] = $dimensions['height'] ?? null;
    }

    // Categories
    $categoryId = $GLOBALS['categoryId'] ?? null;
    if ($categoryId) {
        $mappedData['categories'] = [['id' => $categoryId]];
    }

    // Prices
    if (isset($product['buybox_winner']['price']['value'])) {
        $grossPrice = $product['buybox_winner']['price']['value'];
        $taxRate = getTaxRate(); // e.g., 19.0
        $netPrice = $grossPrice / (1 + $taxRate / 100);

        $mappedData['price'] = [
            [
                'currencyId' => getCurrencyId('EUR'), // Changed to EUR
                'gross' => $grossPrice,
                'net' => $netPrice,
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
    if (!empty($product['images']) && is_array($product['images'])) {
        foreach ($product['images'] as $image) {
            if (!empty($image['link'])) {
                $images[] = $image['link'];
            }
        }
    }
    $mappedData['images'] = $images;

    // Variants
    if (!empty($product['variants']) && is_array($product['variants'])) {
        $mappedData['configuratorSettings'] = mapVariants($product['variants']);
    }

    // Reviews
    if (!empty($product['top_reviews']) && is_array($product['top_reviews'])) {
        $mappedData['productReviews'] = mapReviews($product['top_reviews'], $mappedData['productNumber']);
    }

    // Remove null values from mapped data
    $mappedData = array_filter($mappedData, fn($value) => $value !== null);

    // Log the mapped product data
    logMessage("Mapped product data for ASIN {$mappedData['productNumber']}: " . json_encode($mappedData));

    // Return the mapped data
    return $mappedData;
}

// Function to standardize units using OpenAI API
function standardizeUnits(string $value, string $type): mixed
{
    // Prepare the prompt
    $prompt = "Convert the following $type to standard units compatible with Shopware 6, and provide the result as JSON only without any code block markers or additional text:\n\n$value";

    $response = callOpenAiApi($prompt);

    // Clean up the response by removing any code block markers
    $response = trim($response);
    $response = preg_replace('/^```(?:json)?\s*/', '', $response); // Remove starting ```json or ```
    $response = preg_replace('/\s*```$/', '', $response); // Remove ending ```

    // Parse the JSON response
    $standardizedData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logError('Error parsing OpenAI response: ' . json_last_error_msg() . "\nResponse: $response");
        return null;
    }

    // Log the standardized units
    logMessage("Standardized $type: " . json_encode($standardizedData));

    if ($type === 'weight') {
        return $standardizedData['weight'] ?? null;
    }

    return $standardizedData;
}

// Function to call OpenAI API
function callOpenAiApi(string $prompt): string
{
    global $client, $openAiApiKey, $guzzleHeaders;

    try {
        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $openAiApiKey",
            ]),
            'json' => [
                'model' => 'gpt-4o-mini', // Use the specified model
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ]
                ],
                'max_tokens' => 150,
                'temperature' => 0,
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (isset($data['choices'][0]['message']['content'])) {
            $apiResponse = $data['choices'][0]['message']['content'];

            // Log the raw response
            logMessage("OpenAI API response: $apiResponse");

            return $apiResponse;
        } else {
            throw new Exception('Invalid response from OpenAI API');
        }

    } catch (RequestException $e) {
        logError('OpenAI API Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return '';
    }
}

// Function to format release date
function formatReleaseDate(string $dateString): ?string
{
    $date = date_create_from_format('F j, Y', $dateString);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}

// Function to get category ID based on customFields
function getCategoryId(): string
{
    global $client, $shopwareApiUrl, $webhookData, $guzzleHeaders;

    $collectionId = $webhookData['collection']['id'] ?? null;
    if (!$collectionId) {
        throw new Exception('Collection ID is missing in webhook data.');
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        logMessage("Searching for category with customFields matching collection ID $collectionId");

        $response = $client->post("$shopwareApiUrl/api/search/category", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'customFields.junu_category_collection',
                        'value' => true,
                    ],
                    [
                        'type' => 'equals',
                        'field' => 'customFields.junu_category_collection_id',
                        'value' => $collectionId,
                    ],
                ],
                'includes' => [
                    'category' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'])) {
            $categoryId = $data['data'][0]['id'];
            logMessage("Found matching category ID: $categoryId");
            return $categoryId;
        } else {
            throw new Exception('No matching category found.');
        }

    } catch (RequestException $e) {
        logError('Error fetching category ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to get sales channel ID
function getSalesChannelId(): ?string
{
    global $client, $shopwareApiUrl, $salesChannelName, $guzzleHeaders;

    static $salesChannelId = null;

    if ($salesChannelId) {
        return $salesChannelId;
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/search/sales-channel", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $salesChannelName,
                    ],
                ],
                'includes' => [
                    'sales_channel' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $salesChannelId = $data['data'][0]['id'];
            return $salesChannelId;
        }

        throw new Exception('Sales channel not found.');

    } catch (RequestException $e) {
        logError('Error fetching sales channel ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return null;
    }
}

// Function to get currency ID
function getCurrencyId(string $isoCode): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $currencyIds = [];

    if (isset($currencyIds[$isoCode])) {
        return $currencyIds[$isoCode];
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/search/currency", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'isoCode',
                        'value' => $isoCode,
                    ],
                ],
                'includes' => [
                    'currency' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $currencyId = $data['data'][0]['id'];
            $currencyIds[$isoCode] = $currencyId;
            return $currencyId;
        }

        throw new Exception('Currency not found.');

    } catch (RequestException $e) {
        logError('Error fetching currency ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return '';
    }
}

// Function to get default tax ID
function getDefaultTaxId(): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $defaultTaxId = null;

    if ($defaultTaxId) {
        return $defaultTaxId;
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Fetch the default tax rate (e.g., 19% VAT)
        $response = $client->post("$shopwareApiUrl/api/search/tax", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    ['type' => 'equals', 'field' => 'taxRate', 'value' => 19.0],
                ],
                'includes' => [
                    'tax' => ['id', 'taxRate'],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $defaultTaxId = $data['data'][0]['id'];
            // Store the tax rate for price calculations
            $GLOBALS['defaultTaxRate'] = $data['data'][0]['taxRate'];
            return $defaultTaxId;
        } else {
            throw new Exception('Default tax rate not found.');
        }
    } catch (RequestException $e) {
        logError('Error fetching default tax ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to get default tax rate
function getTaxRate(): float
{
    if (isset($GLOBALS['defaultTaxRate'])) {
        return $GLOBALS['defaultTaxRate'];
    } else {
        getDefaultTaxId(); // This will set $GLOBALS['defaultTaxRate']
        return $GLOBALS['defaultTaxRate'] ?? 19.0;
    }
}

// Function to get Shopware authentication token
function getShopwareToken(): ?string
{
    global $client, $shopwareApiUrl, $shopwareClientId, $shopwareClientSecret, $guzzleHeaders;

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
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (isset($data['access_token'])) {
            $token = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 0;
            $tokenExpiresAt = time() + $expiresIn - 60; // Subtract 60 seconds as a buffer

            return $token;
        } else {
            throw new Exception('Invalid response from Shopware token endpoint.');
        }

    } catch (RequestException $e) {
        logError('Error obtaining Shopware token: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return null;
    }
}

// Function to get or create media folder ID
function getMediaFolderId(): string
{
    global $client, $shopwareApiUrl, $mediaFolderName, $guzzleHeaders;

    static $mediaFolderId = null;

    if ($mediaFolderId) {
        return $mediaFolderId;
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Search for the media folder by name
        $response = $client->post("$shopwareApiUrl/api/search/media-folder", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $mediaFolderName,
                    ],
                ],
                'includes' => [
                    'media_folder' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $mediaFolderId = $data['data'][0]['id'];
            return $mediaFolderId;
        } else {
            // Media folder does not exist, create it
            $mediaFolderId = createMediaFolder();
            return $mediaFolderId;
        }

    } catch (RequestException $e) {
        logError('Error fetching media folder ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to create media folder
function createMediaFolder(): string
{
    global $client, $shopwareApiUrl, $mediaFolderName, $guzzleHeaders;

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Get default configuration ID for media folders
        $configurationId = getDefaultMediaFolderConfigurationId();

        // Generate a valid UUID without dashes
        $mediaFolderId = Uuid::uuid4()->getHex();

        $client->post("$shopwareApiUrl/api/media-folder", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'id' => $mediaFolderId,
                'name' => $mediaFolderName,
                'useParentConfiguration' => true,
                'configurationId' => $configurationId,
            ],
        ]);

        logMessage("Media folder created with ID: $mediaFolderId");

        return $mediaFolderId;

    } catch (RequestException $e) {
        logError('Error creating media folder: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to get default media folder configuration ID
function getDefaultMediaFolderConfigurationId(): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $configurationId = null;

    if ($configurationId) {
        return $configurationId;
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/search/media-folder-configuration", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'limit' => 1,
                'includes' => [
                    'media_folder_configuration' => ['id']
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $configurationId = $data['data'][0]['id'];
            return $configurationId;
        } else {
            throw new Exception('No media folder configuration found.');
        }

    } catch (RequestException $e) {
        logError('Error fetching media folder configuration ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to get or create manufacturer
function getManufacturerId(string $manufacturerName): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $manufacturerCache = [];

    if (isset($manufacturerCache[$manufacturerName])) {
        return $manufacturerCache[$manufacturerName];
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Search for the manufacturer by name
        $response = $client->post("$shopwareApiUrl/api/search/product-manufacturer", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'name',
                        'value' => $manufacturerName,
                    ],
                ],
                'includes' => [
                    'product_manufacturer' => ['id'],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            // Manufacturer exists, return its ID
            $manufacturerId = $data['data'][0]['id'];
        } else {
            // Manufacturer doesn't exist, create it
            $manufacturerId = createManufacturer($manufacturerName);
        }

        // Cache the manufacturer ID
        $manufacturerCache[$manufacturerName] = $manufacturerId;

        return $manufacturerId;
    } catch (RequestException $e) {
        logError('Error fetching manufacturer ID: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to create manufacturer
function createManufacturer(string $manufacturerName): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Generate a unique ID for the manufacturer
        $manufacturerId = Uuid::uuid4()->getHex();

        // Create the manufacturer
        $client->post("$shopwareApiUrl/api/product-manufacturer", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'id' => $manufacturerId,
                'name' => $manufacturerName,
            ],
        ]);

        logMessage("Manufacturer '$manufacturerName' created with ID: $manufacturerId");

        return $manufacturerId;
    } catch (RequestException $e) {
        logError('Error creating manufacturer: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to create product in Shopware
function createProductInShopware(array $productData): void
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Upload images and get media IDs
        if (!empty($productData['images'])) {
            $mediaIds = uploadImages($productData['images'], $token);
            unset($productData['images']);

            // Set cover image
            if (!empty($mediaIds)) {
                $productData['cover'] = ['mediaId' => $mediaIds[0]];
                $productData['media'] = array_map(fn($mediaId) => ['mediaId' => $mediaId], $mediaIds);
            }
        }

        // Remove null values
        $productData = array_filter($productData, fn($value) => $value !== null);

        // Generate a unique ID for the product using Ramsey UUID
        $productId = Uuid::uuid4()->getHex();
        $productData['id'] = $productId;

        // Create product
        $client->post("$shopwareApiUrl/api/product", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => $productData, // Send product data directly, not as an array
        ]);

        // Product created successfully
        logMessage("Product created successfully with ID $productId");

    } catch (RequestException $e) {
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
        logError('Error creating product: ' . $e->getMessage() . "\nResponse Body: $responseBody\n" . $e->getTraceAsString());
    }
}


// Function to upload images to Shopware using URL
function uploadImages(array $imageUrls, string $token): array
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    $mediaFolderId = getMediaFolderId();
    $mediaIds = [];

    foreach ($imageUrls as $imageUrl) {
        try {
            // Extract filename from image URL
            $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
            $fileNameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);

            // Check if media with this filename already exists
            $existingMediaId = findMediaByFilename($fileNameWithoutExtension, $token);
            if ($existingMediaId) {
                logMessage("Media with filename $filename already exists with ID: $existingMediaId");
                $mediaIds[] = $existingMediaId;
                continue;
            }

            // Generate a unique media ID (32-character hex without dashes)
            $mediaId = Uuid::uuid4()->getHex();

            // Create media entity with mediaFolderId and id
            $response = $client->post("$shopwareApiUrl/api/media", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json' => [
                    'id' => $mediaId,
                    'mediaFolderId' => $mediaFolderId,
                    // 'alt' => 'Optional alt text', // Add alt text if available
                ],
            ]);

            if ($response->getStatusCode() !== 204) {
                $responseBody = $response->getBody()->getContents();
                throw new Exception("Failed to create media entity. Response: $responseBody");
            }

            // Upload the image by providing the URL
            // Include 'fileName' as a query parameter
            $uploadUrl = "$shopwareApiUrl/api/_action/media/$mediaId/upload?fileName=" . urlencode($fileNameWithoutExtension);

            $uploadResponse = $client->post($uploadUrl, [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json' => [
                    'url' => $imageUrl,
                ],
            ]);

            if ($uploadResponse->getStatusCode() !== 204) {
                $responseBody = $uploadResponse->getBody()->getContents();
                throw new Exception("Failed to upload media. Response: $responseBody");
            }

            $mediaIds[] = $mediaId;

            // Log successful image upload
            logMessage("Uploaded image: $imageUrl with media ID: $mediaId");

        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            logError('Error uploading image: ' . $e->getMessage() . "\nResponse Body: $responseBody\n" . $e->getTraceAsString());
        } catch (Exception $e) {
            logError('Error uploading image: ' . $e->getMessage());
        }
    }

    return $mediaIds;
}

// Function to find existing media by filename
function findMediaByFilename(string $fileNameWithoutExtension, string $token): ?string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    try {
        $response = $client->post("$shopwareApiUrl/api/search/media", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'fileName',
                        'value' => $fileNameWithoutExtension,
                    ],
                ],
                'includes' => [
                    'media' => ['id'],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $data['data'][0]['id'] ?? null;

    } catch (RequestException $e) {
        logError('Error searching for media by filename: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return null;
    }
}

// Function to map variants
function mapVariants(array $variants): array
{
    global $customFieldsPrefix;

    $mappedVariants = [];

    foreach ($variants as $variant) {
        $variantData = [];

        // Options (e.g., size, color)
        $options = [];
        if (!empty($variant['dimensions']) && is_array($variant['dimensions'])) {
            foreach ($variant['dimensions'] as $dimension) {
                if (!empty($dimension['name']) && !empty($dimension['value'])) {
                    $groupName = $dimension['name'];
                    $optionName = $dimension['value'];

                    // Get or create option group and option
                    $groupId = getOptionGroupId($groupName);
                    $optionId = getOptionId($groupId, $optionName);

                    $options[] = [
                        'optionId' => $optionId,
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
        $variantData['customFields'] = array_filter($customFields, fn($value) => $value !== null);

        $mappedVariants[] = $variantData;
    }

    return $mappedVariants;
}

// Function to get or create option group
function getOptionGroupId(string $groupName): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $optionGroupCache = [];

    if (isset($optionGroupCache[$groupName])) {
        return $optionGroupCache[$groupName];
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Search for existing property group
        $response = $client->post("$shopwareApiUrl/api/search/property-group", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    ['type' => 'equals', 'field' => 'name', 'value' => $groupName],
                ],
                'includes' => [
                    'property_group' => ['id'],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $groupId = $data['data'][0]['id'];
        } else {
            // Create new property group
            $groupId = Uuid::uuid4()->getHex();

            $client->post("$shopwareApiUrl/api/property-group", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json' => [
                    'id'   => $groupId,
                    'name' => $groupName,
                ],
            ]);
        }

        $optionGroupCache[$groupName] = $groupId;
        return $groupId;

    } catch (RequestException $e) {
        logError('Error fetching or creating option group: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to get or create option
function getOptionId(string $groupId, string $optionName): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $optionCache = [];

    $cacheKey = $groupId . '_' . $optionName;
    if (isset($optionCache[$cacheKey])) {
        return $optionCache[$cacheKey];
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    try {
        // Search for existing property group option
        $response = $client->post("$shopwareApiUrl/api/search/property-group-option", [
            'headers' => array_merge($guzzleHeaders, [
                'Authorization' => "Bearer $token",
            ]),
            'json' => [
                'filter' => [
                    ['type' => 'equals', 'field' => 'name', 'value' => $optionName],
                    ['type' => 'equals', 'field' => 'groupId', 'value' => $groupId],
                ],
                'includes' => [
                    'property_group_option' => ['id'],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $optionId = $data['data'][0]['id'];
        } else {
            // Create new property group option
            $optionId = Uuid::uuid4()->getHex();

            $client->post("$shopwareApiUrl/api/property-group-option", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json' => [
                    'id'      => $optionId,
                    'groupId' => $groupId,
                    'name'    => $optionName,
                ],
            ]);
        }

        $optionCache[$cacheKey] = $optionId;
        return $optionId;

    } catch (RequestException $e) {
        logError('Error fetching or creating option: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}

// Function to map reviews
function mapReviews(array $reviews, string $productId): array
{
    global $customFieldsPrefix;

    $salesChannelId = getSalesChannelId();

    $mappedReviews = [];

    foreach ($reviews as $review) {
        $reviewData = [];

        $reviewData['title'] = $review['title'] ?? 'No Title';
        $reviewData['content'] = $review['body'] ?? '';
        $reviewData['points'] = $review['rating'] ?? 0;
        $reviewData['customerName'] = $review['profile']['name'] ?? 'Anonymous';
        $reviewData['createdAt'] = isset($review['date']['utc']) ? date('Y-m-d H:i:s', strtotime($review['date']['utc'])) : date('Y-m-d H:i:s');
        $reviewData['status'] = true;
        $reviewData['productId'] = $productId;
        $reviewData['salesChannelId'] = $salesChannelId;

        // Custom fields
        $customFields = [];
        $customFields[$customFieldsPrefix . 'reviewId'] = $review['id'] ?? null;
        $customFields[$customFieldsPrefix . 'verifiedPurchase'] = $review['verified_purchase'] ?? null;
        $customFields[$customFieldsPrefix . 'helpfulVotes'] = $review['helpful_votes'] ?? null;

        $reviewData['customFields'] = array_filter($customFields, fn($value) => $value !== null);

        $mappedReviews[] = $reviewData;
    }

    return $mappedReviews;
}
