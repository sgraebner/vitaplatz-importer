<?php
// import.php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuration
$shopwareApiUrl        = rtrim($_ENV['SHOPWARE_API_URL'] ?? '', '/');
$shopwareClientId      = $_ENV['SHOPWARE_CLIENT_ID'] ?? '';
$shopwareClientSecret  = $_ENV['SHOPWARE_CLIENT_SECRET'] ?? '';
$salesChannelName      = $_ENV['SALES_CHANNEL_NAME'] ?? '';
$customFieldsPrefix    = $_ENV['CUSTOM_FIELDS_PREFIX'] ?? '';
$openAiApiKey          = $_ENV['OPENAI_API_KEY'] ?? '';
$anthropicApiKey       = $_ENV['ANTHROPIC_API_KEY'] ?? '';
$mediaFolderName       = $_ENV['MEDIA_FOLDER_NAME'] ?? 'Default Media Folder';

// Validate environment variables
$requiredEnvVars = [
    'SHOPWARE_API_URL',
    'SHOPWARE_CLIENT_ID',
    'SHOPWARE_CLIENT_SECRET',
    'SALES_CHANNEL_NAME',
    'CUSTOM_FIELDS_PREFIX',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
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
    'timeout'         => 60,
    'connect_timeout' => 30,
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
    'Accept'       => 'application/json',
];

// Rate limiter settings
$rateLimiter = [
    'max_requests'       => 5,    // Maximum requests
    'per_seconds'        => 1,    // Per number of seconds
    'request_timestamps' => [],
];

// Function to enforce rate limiting
function enforceRateLimit(): void
{
    global $rateLimiter;

    $currentTime = microtime(true);
    $rateLimiter['request_timestamps'][] = $currentTime;

    // Remove timestamps older than the time window
    $rateLimiter['request_timestamps'] = array_filter(
        $rateLimiter['request_timestamps'],
        function ($timestamp) use ($currentTime, $rateLimiter) {
            return ($currentTime - $timestamp) <= $rateLimiter['per_seconds'];
        }
    );

    // Re-index the array keys to ensure they start from 0
    $rateLimiter['request_timestamps'] = array_values($rateLimiter['request_timestamps']);

    if (count($rateLimiter['request_timestamps']) > $rateLimiter['max_requests']) {
        // Calculate sleep time
        $oldestRequest = $rateLimiter['request_timestamps'][0];
        $sleepTime     = $rateLimiter['per_seconds'] - ($currentTime - $oldestRequest);

        if ($sleepTime > 0) {
            usleep($sleepTime * 1e6); // Convert seconds to microseconds
        }
    }
}


// Function to log errors immediately
function logError(string $message): void
{
    global $errorLogFile;
    $timestamp  = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] ERROR: $message" . PHP_EOL;
    // Write the error message to the log file
    file_put_contents($errorLogFile, $logMessage, FILE_APPEND);
}

// Function to log general messages
function logMessage(string $message): void
{
    global $generalLogFile;
    $timestamp  = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] INFO: $message" . PHP_EOL;
    // Write the message to the log file
    file_put_contents($generalLogFile, $logMessage, FILE_APPEND);
}

// Main processing logic
try {
    // Get the list of collection directories in the "processing" folder
    $processingDir = __DIR__ . '/processing';

    if (!is_dir($processingDir)) {
        throw new Exception('Processing directory does not exist: ' . $processingDir);
    }

    // Get list of collection directories
    $collectionDirs = array_filter(glob($processingDir . '/*'), 'is_dir');

    foreach ($collectionDirs as $collectionDir) {
        // The collection ID is the folder name
        $collectionId = basename($collectionDir);

        // Set webhook data accordingly
        $webhookData = [
            'collection' => [
                'id' => $collectionId,
            ],
        ];

        // Set webhook data globally for access in other functions (if needed)
        $GLOBALS['webhookData'] = $webhookData;

        // **Reset the global categoryId for the new collection**
        unset($GLOBALS['categoryId']); // or $GLOBALS['categoryId'] = null;

        // Get the list of JSON files in the collection directory
        $jsonFiles = glob($collectionDir . '/*.json');

        if (empty($jsonFiles)) {
            logMessage("No JSON files found in collection directory: $collectionDir");
            // Optionally remove the empty directory
            // rmdir($collectionDir);
            continue;
        }

        // Process each JSON file
        foreach ($jsonFiles as $jsonFile) {
            processJsonFile($jsonFile);

            // After processing, remove the file
            unlink($jsonFile);
        }

        // After processing all files, if the directory is empty, remove it
        if (count(glob($collectionDir . '/*')) === 0) {
            rmdir($collectionDir);
        }
    }

} catch (Exception $e) {
    logError($e->getMessage() . "\n" . $e->getTraceAsString());
    exit('An error occurred: ' . $e->getMessage());
}


// Function to process a single JSON file
function processJsonFile(string $filePath): void
{
    try {
        // Log the processing of the file
        logMessage("Processing JSON file: $filePath");

        // Read the JSON file
        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            throw new Exception("Failed to read JSON file: $filePath");
        }

        $jsonData = json_decode($jsonContent, true, flags: JSON_THROW_ON_ERROR);

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

// ... [Rest of your functions remain unchanged] ...
// (The rest of the functions stay the same as in your original script)


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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $productNumber) {
            return $client->post("$shopwareApiUrl/api/search/product", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'productNumber',
                            'value' => $productNumber,
                        ],
                    ],
                    'includes' => [
                        'product' => ['id']
                    ],
                ],
            ]);
        });

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
    $mappedData['name']          = $product['title'] ?? 'Unnamed Product';
    $mappedData['ean']           = $product['ean'] ?? null;

    // Generate product and meta descriptions using Anthropic AI
    $generatedDescriptions = generateDescriptions($product['title'], $product['keywords'] ?? '');

    if ($generatedDescriptions) {
        $mappedData['name']            = $generatedDescriptions['newTitle'];
        $mappedData['description']     = $generatedDescriptions['productDescription'];
        $mappedData['metaDescription'] = $generatedDescriptions['metaDescription'];
    
        // Ensure metaDescription does not exceed 255 characters
        if (strlen($mappedData['metaDescription']) > 255) {
            $mappedData['metaDescription'] = substr($mappedData['metaDescription'], 0, 252) . '...';
        }
        if (strlen($mappedData['name']) > 255) {
            $mappedData['name'] = substr($mappedData['name'], 0, 252) . '...';
        }
        $customFields['customFields.twt_modern_pro_custom_field__product__short_description']     = $mappedData['metaDescription']; // TWT CUSTOM FIELD
    } else {
        $mappedData['description']     = $product['description'] ?? '';
    }
    

    $mappedData['releaseDate'] = isset($product['first_available']['raw']) ? formatReleaseDate($product['first_available']['raw']) : null;

    $mappedData['keywords'] = $product['keywords']?? '';
    $mappedData['customSearchKeywords'] = $product['keywords_list'] ?? [];

    // Get or create manufacturer and set manufacturerId
    $manufacturerName             = $product['brand'] ?? 'Unknown';
    $manufacturerId               = getManufacturerId($manufacturerName);
    $mappedData['manufacturerId'] = $manufacturerId;

    // Provide taxId
    $mappedData['taxId'] = getDefaultTaxId();

    // Custom fields
    $customFields = [];
    $customFields[$customFieldsPrefix . 'parentAsin']     = $product['parent_asin'] ?? null;
    $customFields[$customFieldsPrefix . 'productLink']    = $product['link'] ?? null;
    $customFields[$customFieldsPrefix . 'shippingWeight'] = $product['shipping_weight'] ?? null;
    $customFields[$customFieldsPrefix . 'deliveryMessage'] = $product['delivery_message'] ?? null;
    $customFields[$customFieldsPrefix . 'subTitle']       = $product['sub_title']['text'] ?? null;
    $customFields[$customFieldsPrefix . 'ratingsTotal']   = $product['ratings_total'] ?? null;
    $customFields[$customFieldsPrefix . 'reviewsTotal']   = $product['reviews_total'] ?? null;
    $customFields[$customFieldsPrefix . 'isBundle']       = $product['is_bundle'] ?? null;
    $customFields[$customFieldsPrefix . 'lastUpdate']     = (new \DateTime())->format('c');
    // ... Add other custom fields as per mapping table

    if (!empty($product['feature_bullets']) && is_array($product['feature_bullets'])) {
        $featureBulletsHtml = '<ul>';
        foreach ($product['feature_bullets'] as $bullet) {
            $featureBulletsHtml .= '<li>' . htmlspecialchars($bullet, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $featureBulletsHtml .= '</ul>';
        $customFields[$customFieldsPrefix . 'features'] = $featureBulletsHtml;
    }

    // Set imported and anthropic custom fields to true
    $customFields[$customFieldsPrefix . 'imported']  = true;
    $customFields[$customFieldsPrefix . 'anthropic'] = true;

    $mappedData['customFields'] = array_filter($customFields, fn($value) => $value !== null);

    // Dimensions and weight (standardize via OpenAI API)
    $weightValue          = isset($product['weight']) ? standardizeUnits($product['weight'], 'weight') : null;
    $mappedData['weight'] = is_numeric($weightValue) ? (float)$weightValue : null;

    $dimensions = isset($product['dimensions']) ? standardizeUnits($product['dimensions'], 'dimensions') : null;

    if ($dimensions) {
        $mappedData['length'] = $dimensions['length'] ?? null;
        $mappedData['width']  = $dimensions['width'] ?? null;
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
        $taxRate    = getTaxRate(); // e.g., 19.0
        $netPrice   = $grossPrice / (1 + $taxRate / 100);

        $mappedData['price'] = [
            [
                'currencyId' => getCurrencyId('EUR'),
                'gross'      => $grossPrice,
                'net'        => $netPrice,
                'linked'     => false,
            ]
        ];
    }

    // Stock and availability
    $availabilityType     = $product['buybox_winner']['availability']['type'] ?? 'out_of_stock';
    $mappedData['stock']  = $availabilityType === 'in_stock' ? 100 : 0;
    $mappedData['active'] = $availabilityType === 'in_stock';

    // Sales channel assignment
    $mappedData['visibilities'] = [
        [
            'salesChannelId' => getSalesChannelId(),
            'visibility'     => 30, // Visibility all
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

    // Properties
    if (!empty($product['attributes']) && is_array($product['attributes'])) {
        $mappedData['properties'] = mapProperties($product['attributes']);
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
    $prompt = "Convert the following $type to standard units compatible with Shopware 6, "
        . "and provide the result as JSON only without any code block markers or additional text:\n\n$value";

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $prompt, $openAiApiKey, $guzzleHeaders) {
            return $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $openAiApiKey",
                ]),
                'json'    => [
                    'model'       => 'gpt-4o-mini', // Use the specified model
                    'messages'    => [
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ]
                    ],
                    'max_tokens'  => 150,
                    'temperature' => 0,
                ],
            ]);
        });

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

        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $collectionId) {
            return $client->post("$shopwareApiUrl/api/search/category", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'customFields.junu_category_collection',
                            'value' => true,
                        ],
                        [
                            'type'  => 'equals',
                            'field' => 'customFields.junu_category_collection_id',
                            'value' => $collectionId,
                        ],
                    ],
                    'includes' => [
                        'category' => ['id']
                    ],
                ],
            ]);
        });

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $salesChannelName) {
            return $client->post("$shopwareApiUrl/api/search/sales-channel", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'name',
                            'value' => $salesChannelName,
                        ],
                    ],
                    'includes' => [
                        'sales_channel' => ['id']
                    ],
                ],
            ]);
        });

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $isoCode) {
            return $client->post("$shopwareApiUrl/api/search/currency", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'isoCode',
                            'value' => $isoCode,
                        ],
                    ],
                    'includes' => [
                        'currency' => ['id']
                    ],
                ],
            ]);
        });

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $currencyId           = $data['data'][0]['id'];
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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token) {
            return $client->post("$shopwareApiUrl/api/search/tax", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        ['type' => 'equals', 'field' => 'taxRate', 'value' => 19.0],
                    ],
                    'includes' => [
                        'tax' => ['id', 'taxRate'],
                    ],
                ],
            ]);
        });

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $defaultTaxId               = $data['data'][0]['id'];
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

// Function to get default language ID
// Function to get default language ID from the sales channel
function getDefaultLanguageId(): string
{
    global $client, $shopwareApiUrl, $guzzleHeaders;

    static $defaultLanguageId = null;

    if ($defaultLanguageId) {
        return $defaultLanguageId;
    }

    $token = getShopwareToken();
    if (!$token) {
        throw new Exception('Unable to obtain Shopware token.');
    }

    // Get the sales channel ID
    $salesChannelId = getSalesChannelId();

    try {
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $salesChannelId) {
            return $client->get("$shopwareApiUrl/api/sales-channel/$salesChannelId", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'query' => [
                    'associations' => [
                        'language' => [],
                    ],
                ],
            ]);
        });

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data']['languageId'])) {
            $defaultLanguageId = $data['data']['languageId'];
            return $defaultLanguageId;
        } else {
            throw new Exception('Default language ID not found in sales channel.');
        }
    } catch (RequestException $e) {
        logError('Error fetching default language ID from sales channel: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}


// Function to get Shopware authentication token
function getShopwareToken(): ?string
{
    global $client, $shopwareApiUrl, $shopwareClientId, $shopwareClientSecret;

    static $token          = null;
    static $tokenExpiresAt = null;

    // Check if token is still valid
    if ($token && $tokenExpiresAt && $tokenExpiresAt > time()) {
        return $token;
    }

    try {
        $response = $client->post("$shopwareApiUrl/api/oauth/token", [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $shopwareClientId,
                'client_secret' => $shopwareClientSecret,
            ],
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (isset($data['access_token'])) {
            $token          = $data['access_token'];
            $expiresIn      = $data['expires_in'] ?? 0;
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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $mediaFolderName) {
            return $client->post("$shopwareApiUrl/api/search/media-folder", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'name',
                            'value' => $mediaFolderName,
                        ],
                    ],
                    'includes' => [
                        'media_folder' => ['id']
                    ],
                ],
            ]);
        });

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

        enforceRateLimit();
        apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $mediaFolderId, $mediaFolderName, $configurationId) {
            return $client->post("$shopwareApiUrl/api/media-folder", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'id'                    => $mediaFolderId,
                    'name'                  => $mediaFolderName,
                    'useParentConfiguration' => true,
                    'configurationId'       => $configurationId,
                ],
            ]);
        });

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token) {
            return $client->post("$shopwareApiUrl/api/search/media-folder-configuration", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'limit'    => 1,
                    'includes' => [
                        'media_folder_configuration' => ['id']
                    ],
                ],
            ]);
        });

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $manufacturerName) {
            return $client->post("$shopwareApiUrl/api/search/product-manufacturer", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'name',
                            'value' => $manufacturerName,
                        ],
                    ],
                    'includes' => [
                        'product_manufacturer' => ['id'],
                    ],
                ],
            ]);
        });

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
        enforceRateLimit();
        apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $manufacturerId, $manufacturerName) {
            return $client->post("$shopwareApiUrl/api/product-manufacturer", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'id'   => $manufacturerId,
                    'name' => $manufacturerName,
                ],
            ]);
        });

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
        $productId          = Uuid::uuid4()->getHex();
        $productData['id'] = $productId;

        logMessage("Product data: " . json_encode($productData));
        // Create product
        enforceRateLimit();
        apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $productData) {
            return $client->post("$shopwareApiUrl/api/product", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => $productData, // Send product data directly, not as an array
            ]);
        });

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
    $mediaIds      = [];

    $uploadedMediaCache = [];

    foreach ($imageUrls as $imageUrl) {
        try {
            // Extract filename from image URL
            $filename                = basename(parse_url($imageUrl, PHP_URL_PATH));
            $fileNameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);

            // Check cache first
            if (isset($uploadedMediaCache[$fileNameWithoutExtension])) {
                $mediaIds[] = $uploadedMediaCache[$fileNameWithoutExtension];
                continue;
            }

            // Check if media with this filename already exists
            $existingMediaId = findMediaByFilename($fileNameWithoutExtension, $token);
            if ($existingMediaId) {
                logMessage("Media with filename $filename already exists with ID: $existingMediaId");
                $mediaIds[] = $existingMediaId;
                // Cache the media ID
                $uploadedMediaCache[$fileNameWithoutExtension] = $existingMediaId;
                continue;
            }

            // Generate a unique media ID (32-character hex without dashes)
            $mediaId = Uuid::uuid4()->getHex();

            // Create media entity with mediaFolderId and id
            enforceRateLimit();
            $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $mediaId, $mediaFolderId) {
                return $client->post("$shopwareApiUrl/api/media", [
                    'headers' => array_merge($guzzleHeaders, [
                        'Authorization' => "Bearer $token",
                    ]),
                    'json'    => [
                        'id'            => $mediaId,
                        'mediaFolderId' => $mediaFolderId,
                    ],
                ]);
            });

            if ($response->getStatusCode() !== 204) {
                $responseBody = $response->getBody()->getContents();
                throw new Exception("Failed to create media entity. Response: $responseBody");
            }

            // Upload the image by providing the URL
            // Include 'fileName' as a query parameter
            $uploadUrl = "$shopwareApiUrl/api/_action/media/$mediaId/upload?fileName=" . urlencode($fileNameWithoutExtension);

            enforceRateLimit();
            $uploadResponse = apiRequestWithRetry(function () use ($client, $uploadUrl, $guzzleHeaders, $token, $imageUrl) {
                return $client->post($uploadUrl, [
                    'headers' => array_merge($guzzleHeaders, [
                        'Authorization' => "Bearer $token",
                    ]),
                    'json'    => [
                        'url' => $imageUrl,
                    ],
                ]);
            });

            if ($uploadResponse->getStatusCode() !== 204) {
                $responseBody = $uploadResponse->getBody()->getContents();
                throw new Exception("Failed to upload media. Response: $responseBody");
            }

            $mediaIds[] = $mediaId;
            // Cache the media ID
            $uploadedMediaCache[$fileNameWithoutExtension] = $mediaId;

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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $fileNameWithoutExtension) {
            return $client->post("$shopwareApiUrl/api/search/media", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        [
                            'type'  => 'equals',
                            'field' => 'fileName',
                            'value' => $fileNameWithoutExtension,
                        ],
                    ],
                    'includes' => [
                        'media' => ['id'],
                    ],
                ],
            ]);
        });

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

    $configuratorSettings = [];
    $optionsPerGroup      = [];

    foreach ($variants as $variant) {
        if (!empty($variant['dimensions']) && is_array($variant['dimensions'])) {
            foreach ($variant['dimensions'] as $dimension) {
                if (!empty($dimension['name']) && !empty($dimension['value'])) {
                    $groupName  = $dimension['name'];
                    $optionName = $dimension['value'];

                    // Get or create option group and option
                    $groupId  = getOptionGroupId($groupName);
                    $optionId = getOptionId($groupId, $optionName);

                    // Create a unique key for the group-option combination
                    $key = $groupId . '_' . $optionId;

                    // Avoid duplicates
                    if (!isset($optionsPerGroup[$key])) {
                        $optionsPerGroup[$key] = [
                            'optionId' => $optionId,
                            'option'   => [
                                'id'    => $optionId,
                                'name'  => $optionName,
                                'group' => [
                                    'id'   => $groupId,
                                    'name' => $groupName
                                ]
                            ]
                        ];
                    }
                }
            }
        }
    }

    // Collect configuratorSettings from optionsPerGroup
    $configuratorSettings = array_values($optionsPerGroup);

    return $configuratorSettings;
}

// Function to map properties
function mapProperties(array $attributes): array
{
    $properties = [];

    foreach ($attributes as $attribute) {
        if (!empty($attribute['name']) && !empty($attribute['value'])) {
            $groupName  = $attribute['name'];
            $optionName = $attribute['value'];

            // Get or create option group and option
            $groupId  = getOptionGroupId($groupName);
            $optionId = getOptionId($groupId, $optionName);

            $properties[] = [
                'id' => $optionId,
            ];
        }
    }

    return $properties;
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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $groupName) {
            return $client->post("$shopwareApiUrl/api/search/property-group", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        ['type' => 'equals', 'field' => 'name', 'value' => $groupName],
                    ],
                    'includes' => [
                        'property_group' => ['id'],
                    ],
                ],
            ]);
        });

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $groupId = $data['data'][0]['id'];
        } else {
            // Create new property group
            $groupId = Uuid::uuid4()->getHex();

            enforceRateLimit();
            apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $groupId, $groupName) {
                return $client->post("$shopwareApiUrl/api/property-group", [
                    'headers' => array_merge($guzzleHeaders, [
                        'Authorization' => "Bearer $token",
                    ]),
                    'json'    => [
                        'id'   => $groupId,
                        'name' => $groupName,
                    ],
                ]);
            });
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
        enforceRateLimit();
        $response = apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $groupId, $optionName) {
            return $client->post("$shopwareApiUrl/api/search/property-group-option", [
                'headers' => array_merge($guzzleHeaders, [
                    'Authorization' => "Bearer $token",
                ]),
                'json'    => [
                    'filter'   => [
                        ['type' => 'equals', 'field' => 'name', 'value' => $optionName],
                        ['type' => 'equals', 'field' => 'groupId', 'value' => $groupId],
                    ],
                    'includes' => [
                        'property_group_option' => ['id'],
                    ],
                ],
            ]);
        });

        $data = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!empty($data['data'][0]['id'])) {
            $optionId = $data['data'][0]['id'];
        } else {
            // Create new property group option
            $optionId = Uuid::uuid4()->getHex();

            enforceRateLimit();
            apiRequestWithRetry(function () use ($client, $shopwareApiUrl, $guzzleHeaders, $token, $optionId, $groupId, $optionName) {
                return $client->post("$shopwareApiUrl/api/property-group-option", [
                    'headers' => array_merge($guzzleHeaders, [
                        'Authorization' => "Bearer $token",
                    ]),
                    'json'    => [
                        'id'      => $optionId,
                        'groupId' => $groupId,
                        'name'    => $optionName,
                    ],
                ]);
            });
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

        $reviewData['title']         = $review['title'] ?? 'No Title';
        $reviewData['content']       = $review['body'] ?? '';
        $reviewData['points']        = $review['rating'] ?? 0;
        $reviewData['customerName']  = $review['profile']['name'] ?? 'Anonymous';
        $reviewData['createdAt']     = isset($review['date']['utc']) ? date('Y-m-d H:i:s', strtotime($review['date']['utc'])) : date('Y-m-d H:i:s');
        $reviewData['status']        = true;
        $reviewData['productId']     = $productId;
        $reviewData['salesChannelId'] = $salesChannelId;

        // Custom fields
        $customFields = [];
        $customFields[$customFieldsPrefix . 'reviewId']         = $review['id'] ?? null;
        $customFields[$customFieldsPrefix . 'verifiedPurchase'] = $review['verified_purchase'] ?? null;
        $customFields[$customFieldsPrefix . 'helpfulVotes']     = $review['helpful_votes'] ?? null;

        $reviewData['customFields'] = array_filter($customFields, fn($value) => $value !== null);

        $mappedReviews[] = $reviewData;
    }

    return $mappedReviews;
}

// Function to generate descriptions using Anthropic AI
function generateDescriptions(string $title, string $keywords): ?array
{
    global $anthropicApiKey;

    $prompt = "Erstelle einen neuen, für Shopware 6 optimierten Produkttitel (Hersteller + Model und evtl noch die Variante - sonst nichts), eine detaillierte Produktbeschreibung (formatiert und in HTML) und eine Meta-Beschreibung basierend auf dem folgenden Titel und den Keywords:\n\n"
            . "Titel: $title\n"
            . "Keywords: $keywords\n\n"
            . "Neuer Produkttitel:\n"
            . "Produktbeschreibung:\n"
            . "Meta-Beschreibung:";

    $client = Anthropic::client($anthropicApiKey);

    $result = $client->messages()->create([
        'model' => 'claude-3-opus-20240229',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);

    $descriptions = parseGeneratedDescriptions($result->content[0]->text);

    return $descriptions;
}

function parseGeneratedDescriptions(string $completion): array
{
    $lines               = explode("\n", $completion);
    $newTitle            = '';
    $productDescription  = '';
    $metaDescription     = '';

    $isNewTitle            = false;
    $isProductDescription  = false;
    $isMetaDescription     = false;

    foreach ($lines as $line) {
        if (strpos($line, 'Neuer Produkttitel:') !== false) {
            $isNewTitle           = true;
            $isProductDescription = false;
            $isMetaDescription    = false;
            continue;
        }

        if (strpos($line, 'Produktbeschreibung:') !== false) {
            $isNewTitle           = false;
            $isProductDescription = true;
            $isMetaDescription    = false;
            continue;
        }

        if (strpos($line, 'Meta-Beschreibung:') !== false) {
            $isNewTitle           = false;
            $isProductDescription = false;
            $isMetaDescription    = true;
            continue;
        }

        if ($isNewTitle) {
            $newTitle .= $line . "\n";
        }

        if ($isProductDescription) {
            $productDescription .= $line . "\n";
        }

        if ($isMetaDescription) {
            $metaDescription .= $line . "\n";
        }
    }

    return [
        'newTitle'           => trim($newTitle),
        'productDescription' => trim($productDescription),
        'metaDescription'    => trim($metaDescription),
    ];
}

// Function to make API requests with retry logic
function apiRequestWithRetry(callable $apiCall, int $maxRetries = 5): mixed
{
    $retryCount = 0;
    $waitTime   = 500; // Start with 500 milliseconds

    while ($retryCount < $maxRetries) {
        try {
            return $apiCall();
        } catch (RequestException $e) {
            if ($e->getCode() === 429) {
                // Log the rate limit event
                logMessage("Rate limited. Retrying after {$waitTime}ms...");

                // Wait before retrying
                usleep($waitTime * 1000);

                // Exponential backoff
                $waitTime *= 2;
                $retryCount++;
            } else {
                // Re-throw the exception if it's not a 429
                throw $e;
            }
        }
    }

    throw new Exception("Max retries exceeded for API request.");
}