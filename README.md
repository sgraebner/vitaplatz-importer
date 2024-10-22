# Shopware 6 Product Import Script

This script imports products into Shopware 6 using data from webhooks and APIs.

## Requirements

- PHP 7.4 or higher
- Composer
- Shopware 6 API credentials
- OpenAI API Key
- Anthropic API Key
- Guzzle HTTP client
- Dotenv
- Ramsey UUID

## Installation

1. **Clone the Repository**

   ```bash
   git clone https://github.com/ju-nu/vitaplatz-importer.git
   cd shopware-import
   ```

2. **Install Dependencies**

   ```bash
   composer install
   ```

3. **Create the `.env` File**

   Create a `.env` file in the project root directory with the following content:

   ```dotenv
   SHOPWARE_API_URL=https://your-shopware-instance.com
   SHOPWARE_CLIENT_ID=your-shopware-client-id
   SHOPWARE_CLIENT_SECRET=your-shopware-client-secret
   SALES_CHANNEL_NAME=Your Sales Channel Name
   CUSTOM_FIELDS_PREFIX=your_custom_fields_prefix_
   OPENAI_API_KEY=your-openai-api-key
   ANTHROPIC_API_KEY=your-anthropic-api-key
   MEDIA_FOLDER_NAME=Default Media Folder
   ```

   Replace the placeholders with your actual configuration values.

## Configuration

- **SHOPWARE_API_URL**: The base URL of your Shopware 6 instance.
- **SHOPWARE_CLIENT_ID**: The client ID for Shopware API authentication.
- **SHOPWARE_CLIENT_SECRET**: The client secret for Shopware API authentication.
- **SALES_CHANNEL_NAME**: The name of the sales channel where the products will be imported.
- **CUSTOM_FIELDS_PREFIX**: The prefix for custom fields in Shopware.
- **OPENAI_API_KEY**: Your OpenAI API key.
- **ANTHROPIC_API_KEY**: Your Anthropic API key.
- **MEDIA_FOLDER_NAME**: The name of the media folder in Shopware where images will be stored.

## Usage

### Running the Script

The script `import.php` is designed to be triggered by a webhook. To test it manually, you can use the `test_webhook.php` script.

1. **Start a Local PHP Server**

   ```bash
   php -S localhost:8000
   ```

2. **Run the Test Script**

   In another terminal window, execute:

   ```bash
   php test_webhook.php
   ```

   This will send a test webhook request to `import.php`.

### Testing the Webhook

- **Modify Test Data:**

  You can modify the `test_webhook.php` file to adjust the test data as needed.

- **Sample JSON Pages:**

  Ensure that the URLs provided in `pages` point to accessible JSON files. You may need to create mock JSON files for testing.

### Logs

- **error_log.txt**: Contains error messages and stack traces.
- **general_log.txt**: Contains general information and debug messages.

## Notes

- **Environment Variables:** Ensure that all required environment variables are correctly set in the `.env` file.
- **Rate Limiting:** The script uses rate limiting and retry mechanisms to handle API rate limits.
- **Permissions:** The script requires appropriate permissions to access the Shopware API endpoints for creating products, media, etc.
- **Custom Fields:** Adjust the `CUSTOM_FIELDS_PREFIX` to match your Shopware custom fields configuration.

## Contributing

Feel free to submit issues or pull requests for improvements or bug fixes.

## License

This project is licensed under the MIT License.
