# Google Sheets Data Importer

A Laravel application that connects to Google Sheets, reads spreadsheet
tabs with different structures, and uses database-configured mappings to
transform source columns into destination records.

The project is being developed in stages. This README documents the work
completed through **Stage 4**: project setup, database configuration,
Google Sheets API integration, and the dynamic column-mapping engine.

> **Current scope:** The application can connect to Google Sheets, read
> spreadsheet data, load mapping rules from MySQL, validate values,
> convert data types, and prepare structured records for destination
> tables. Dynamic destination-table creation and data insertion are not
> included in this documented stage.

## Table of contents

-   [Project goals](#project-goals)
-   [Technology stack](#technology-stack)
-   [Project architecture](#project-architecture)
-   [Prerequisites](#prerequisites)
-   [Local development setup on
    macOS](#local-development-setup-on-macos)
-   [Environment configuration](#environment-configuration)
-   [Database design](#database-design)
-   [Google Sheets API configuration](#google-sheets-api-configuration)
-   [Google Sheets integration](#google-sheets-integration)
-   [Column mapping engine](#column-mapping-engine)
-   [Testing with Laravel Tinker](#testing-with-laravel-tinker)
-   [Validation and error handling](#validation-and-error-handling)
-   [Current limitations and next
    steps](#current-limitations-and-next-steps)
-   [Security considerations](#security-considerations)

## Project goals

The application is designed to:

1.  Connect to multiple Google spreadsheets.
2.  Read data from individual tabs within each spreadsheet.
3.  Support spreadsheets with different column names and column orders.
4.  Store source-to-destination column mappings in MySQL rather than
    hardcoding them in application code.
5.  Map one source column to multiple destination tables or columns.
6.  Validate required and nullable values.
7.  Convert incoming values into configured data types.
8.  Prepare structured records for a later database import process.
9.  Provide a foundation for scheduled, recurring imports.

The intended configuration-driven approach means that adding another
spreadsheet or changing its column mapping should primarily involve
updating database configuration rather than writing spreadsheet-specific
PHP code.

## Technology stack

  -----------------------------------------------------------------------
  Technology                          Purpose
  ----------------------------------- -----------------------------------
  PHP                                 Application programming language

  Laravel                             Backend application framework

  MySQL                               Stores spreadsheet configuration,
                                      mappings, and import metadata

  Google Sheets API                   Reads spreadsheet metadata and cell
                                      values

  Google API PHP Client               Authenticates with Google and
                                      communicates with the API

  Composer                            PHP dependency management

  Laravel Tinker                      Interactive testing of application
                                      services and database models

  Carbon                              Date parsing and conversion
  -----------------------------------------------------------------------

## Project architecture

The application is organized around separate responsibilities:

``` text
Google Sheets
     |
     v
GoogleSheetsService
     |
     | Reads spreadsheet metadata, headers and rows
     v
ColumnMappingEngine
     |
     | Loads mappings from MySQL
     | Matches source columns by header
     | Validates and converts values
     v
Structured destination records
     |
     v
(Future stage: destination schema management and data persistence)
```

### Main application components

-   **GoogleSheetsService** --- authenticates with Google and reads
    spreadsheet metadata and tab values.
-   **ColumnMappingEngine** --- loads configured mappings, validates
    spreadsheet headers and row values, converts values, and groups the
    resulting records by destination table.
-   **Eloquent models** --- represent spreadsheets, tabs, column
    mappings, and import logs.

## Prerequisites

The local development environment is intended for macOS and requires:

-   PHP 8.3 or a compatible version supported by the selected Laravel
    release.
-   Composer.
-   MySQL.
-   Node.js and npm, if frontend assets are needed.
-   A Google account with access to Google Cloud Console.
-   A Google spreadsheet that can be shared with a Google service
    account.

Check installed versions:

``` bash
php -v
composer --version
mysql --version
node -v
npm -v
```

## Local development setup on macOS

The development environment can be installed using Homebrew:

``` bash
brew install php
brew install composer
brew install mysql
brew install node
```

Start the MySQL service:

``` bash
brew services start mysql
```

Create a Laravel project if one has not already been created:

``` bash
composer create-project laravel/laravel google-sheets-importer
cd google-sheets-importer
```

Install the Google API PHP client:

``` bash
composer require google/apiclient
```

Create the application database and a dedicated MySQL user. For example,
after connecting to MySQL with an administrative account:

``` sql
CREATE DATABASE google_sheets_importer;

CREATE USER 'sheets_user'@'localhost'
IDENTIFIED BY 'replace-with-a-strong-password';

GRANT ALL PRIVILEGES ON google_sheets_importer.*
TO 'sheets_user'@'localhost';

FLUSH PRIVILEGES;
```

Use a unique local development password and do not commit it to version
control.

## Environment configuration

Configure the Laravel `.env` file with the local database connection:

``` dotenv
APP_NAME="Google Sheets Importer"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=google_sheets_importer
DB_USERNAME=sheets_user
DB_PASSWORD=replace-with-a-strong-password
```

After changing configuration, clear Laravel's cached configuration:

``` bash
php artisan config:clear
```

Run the database migrations:

``` bash
php artisan migrate
```

## Database design

The initial database design separates spreadsheet configuration from
individual tabs and column mappings.

### `google_sheets`

Stores the Google spreadsheets registered with the application.

  Column                       Description
  ---------------------------- ----------------------------------------------
  `id`                         Primary key
  `name`                       Human-readable spreadsheet name
  `spreadsheet_id`             Google spreadsheet identifier; unique
  `is_active`                  Indicates whether the spreadsheet is enabled
  `created_at`, `updated_at`   Laravel timestamps

### `sheet_tabs`

Stores the individual tabs belonging to a registered spreadsheet.

  Column                       Description
  ---------------------------- --------------------------------------
  `id`                         Primary key
  `google_sheet_id`            Foreign key to `google_sheets`
  `tab_name`                   Name of the Google Sheets tab
  `is_active`                  Indicates whether the tab is enabled
  `created_at`, `updated_at`   Laravel timestamps

A spreadsheet can have multiple tabs. Deleting a spreadsheet
configuration cascades to its tab configurations.

### `column_mappings`

Stores the rules that connect source spreadsheet columns to destination
database columns.

  -----------------------------------------------------------------------
  Column                              Description
  ----------------------------------- -----------------------------------
  `id`                                Primary key

  `sheet_tab_id`                      Foreign key to `sheet_tabs`

  `source_column`                     Source header in Google Sheets

  `destination_table`                 Configured destination table name

  `destination_column`                Configured destination column name

  `data_type`                         Expected value type, such as
                                      `string`, `integer`, `decimal`,
                                      `date`, or `boolean`

  `is_nullable`                       Whether a null value is permitted

  `is_required`                       Whether a value must be provided

  `default_value`                     Optional fallback value

  `created_at`, `updated_at`          Laravel timestamps
  -----------------------------------------------------------------------

The mapping design supports one source column mapping to multiple
destination columns, including columns in different destination tables.

### `import_logs`

Provides a place to record import execution history.

The initial table includes:

-   Optional references to a spreadsheet and tab.
-   Spreadsheet name and ID snapshots.
-   Import status.
-   Rows read, imported, and failed.
-   Error message.
-   Start and finish timestamps.
-   Creation and update timestamps.

The log table is part of the initial database design. The complete
workflow for writing and managing import logs is a later implementation
task.

## Eloquent models and relationships

The application uses four Eloquent models:

-   `GoogleSheet`
    -   Has many `SheetTab` records.
    -   Has many `ImportLog` records.
-   `SheetTab`
    -   Belongs to a `GoogleSheet`.
    -   Has many `ColumnMapping` records.
    -   Has many `ImportLog` records.
-   `ColumnMapping`
    -   Belongs to a `SheetTab`.
-   `ImportLog`
    -   Belongs to a `GoogleSheet` and a `SheetTab`, where those
        references are available.

Boolean fields are cast to booleans, and import log timestamps are cast
to date-time values.

## Seed data

An example seeder, `GoogleSheetsSeeder`, creates a sample spreadsheet
configuration, a `Sales` tab, and example column mappings.

The example mappings are:

  Source column    Destination table     Destination column   Type
  ---------------- --------------------- -------------------- -----------
  `date`           `sales`               `date`               `date`
  `product_name`   `sales`               `name`               `string`
  `price`          `sales`               `product_price`      `decimal`
  `amount`         `sales`               `amount`             `integer`
  `amount`         `financial_reports`   `total_amount`       `integer`

The same `amount` source column is deliberately mapped to two
destinations to demonstrate one-to-many mapping.

Run the seeder:

``` bash
php artisan db:seed
```

The spreadsheet ID in the example seeder is a placeholder. Replace it
with the ID of a real spreadsheet before attempting to retrieve live
data.

## Google Sheets API configuration

The application uses a Google service account for server-to-server
authentication.

### Google Cloud setup

1.  Create a project in [Google Cloud
    Console](https://console.cloud.google.com/).
2.  Enable the Google Sheets API for that project.
3.  Create a service account.
4.  Generate a JSON key for the service account.
5.  Store the JSON key securely outside version control.
6.  Share the spreadsheet with the service account email address,
    granting Viewer access for read-only imports.

The service account's JSON key authenticates the application. Sharing
the spreadsheet grants that service account access to the specific
spreadsheet.

### Store credentials locally

Create a private directory:

``` bash
mkdir -p storage/app/private/google
```

Move the downloaded service account JSON file into that directory and
name it:

``` text
storage/app/private/google/service-account.json
```

Add the following to `.env`:

``` dotenv
GOOGLE_APPLICATION_CREDENTIALS=storage/app/private/google/service-account.json
```

Add a configuration file at `config/google-sheets.php`:

``` php
<?php

return [
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),

    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets.readonly',
    ],
];
```

The application service resolves this relative path using Laravel's
`base_path()` helper.

Add an additional safeguard to `.gitignore`:

``` gitignore
/storage/app/private/google/*.json
```

Never commit service account private keys to a repository or expose them
in logs, screenshots, or public documentation.

## Google Sheets integration

The Google API PHP client is installed through Composer:

``` bash
composer require google/apiclient
```

The `GoogleSheetsService` is responsible for authenticating and
communicating with the Google Sheets API.

### Authentication

The service:

-   Reads the credential path from Laravel configuration.
-   Checks that the JSON credential file exists.
-   Creates a Google API client.
-   Configures the application name.
-   Loads the service account credentials.
-   Requests the read-only Google Sheets scope.
-   Creates a Sheets API service instance.

### Reading spreadsheet metadata

The `getSpreadsheetInfo(string $spreadsheetId): array` method retrieves:

-   Spreadsheet ID.
-   Spreadsheet title.
-   Tab IDs.
-   Tab titles.
-   Tab indexes.

This allows the application to inspect available tabs without hardcoding
their names.

### Reading tab values

The `readTab(string $spreadsheetId, string $tabName): array` method:

1.  Builds an A1 notation range for the requested tab.
2.  Requests values from the Google Sheets API.
3.  Uses `ROWS` as the major dimension.
4.  Uses `UNFORMATTED_VALUE` to retrieve underlying cell values.
5.  Treats the first row as the header row.
6.  Converts subsequent rows into associative arrays keyed by header.
7.  Pads missing trailing cell values with `null`.
8.  Rejects empty or duplicate column headers.

The returned structure has this shape:

``` php
[
    'headers' => [
        'date',
        'product_name',
        'price',
        'amount',
    ],

    'rows' => [
        [
            'date' => '2026-11-12',
            'product_name' => 'Laptop',
            'price' => 1200,
            'amount' => 2,
        ],
    ],
]
```

Because rows are associative arrays, the importer can match columns by
their header names rather than relying on fixed column positions.

## Column mapping engine

The `ColumnMappingEngine` reads mapping rules from MySQL through the
`SheetTab` relationship:

``` php
$mappings = $sheetTab->columnMappings()->get();
```

It then transforms spreadsheet data into destination-oriented records.

### Processing steps

1.  Read the spreadsheet headers and rows.
2.  Load the configured mappings for the selected tab.
3.  Validate destination table and column identifier syntax.
4.  Validate configured data types.
5.  Confirm that every configured source header exists in the
    spreadsheet.
6.  Resolve empty values, nullability, required values, and defaults.
7.  Convert values to their configured types.
8.  Group transformed records by destination table.
9.  Collect row-level validation errors.
10. Return the prepared records and processing statistics.

### Supported data types

  -----------------------------------------------------------------------
  Type                                Conversion
  ----------------------------------- -----------------------------------
  `string`                            Converts the value to a string

  `integer`                           Validates and converts an integer

  `decimal`                           Validates a numeric value while
                                      retaining a string representation

  `boolean`                           Supports values such as `true`,
                                      `false`, `yes`, `no`, `1`, and `0`

  `date`                              Parses date strings and Google
                                      Sheets numeric date serials
  -----------------------------------------------------------------------

### One-to-many mapping example

A source row can produce records for more than one destination table.

For example, a source value of `amount = 2` can be transformed into:

``` php
[
    'sales' => [
        [
            'amount' => 2,
        ],
    ],

    'financial_reports' => [
        [
            'total_amount' => 2,
        ],
    ],
]
```

### Engine result

The mapping engine returns:

``` php
[
    'records' => $records,
    'rows_read' => 3,
    'rows_valid' => 3,
    'rows_failed' => 0,
    'errors' => [],
]
```

Each validation error includes the source row number, source column,
destination table, destination column, and error message.

## Testing with Laravel Tinker

Laravel Tinker provides an interactive shell for testing the database
models and application services.

Start it with:

``` bash
php artisan tinker
```

### 1. Load the configured tab

``` php
$tab = App\Models\SheetTab::with('googleSheet')->first();
```

Inspect its mappings:

``` php
$tab->columnMappings;
```

### 2. Test Google Sheets metadata retrieval

``` php
$googleSheets = app(App\Services\GoogleSheetsService::class);

$info = $googleSheets->getSpreadsheetInfo(
    $tab->googleSheet->spreadsheet_id
);

$info;
```

### 3. Read a real spreadsheet tab

``` php
$sheetData = $googleSheets->readTab(
    $tab->googleSheet->spreadsheet_id,
    $tab->tab_name
);

$sheetData['headers'];
$sheetData['rows'];
```

### 4. Test the mapping engine

``` php
$engine = app(App\Services\ColumnMappingEngine::class);

$result = $engine->transform($tab, $sheetData);
```

Inspect the result:

``` php
$result['records'];
$result['rows_read'];
$result['rows_valid'];
$result['rows_failed'];
$result['errors'];
```

At this point, the application has prepared the destination records. The
database persistence workflow is not part of the current documented
scope.

To exit Tinker, type:

``` text
exit
```

If terminal output opens in a pager and shows `(END)`, press `q` to
return to the shell.

## Validation and error handling

The mapping engine is designed to reject invalid input rather than
silently import incorrect values.

Examples include:

-   A configured source header is missing.
-   A spreadsheet has empty or duplicate headers.
-   A required value is missing.
-   A value cannot be converted to its configured type.
-   A destination identifier contains unsupported characters.
-   A configured data type is not supported.

Invalid rows are excluded from the prepared records, and errors are
returned for inspection.

## Current limitations and next steps

This README documents work completed through Stage 4. The following
capabilities are not yet part of this implementation:

-   Automatically creating destination tables and columns.
-   Inserting transformed records into destination tables.
-   Preventing duplicate records across repeated imports.
-   Recording completed and failed import executions in `import_logs`.
-   Running imports automatically on a daily schedule.
-   Providing an administrative user interface for managing spreadsheets
    and mappings.
-   Processing very large spreadsheets through end-to-end streaming or
    chunked reads.

These are planned areas for subsequent development.

## Security considerations

-   Keep `.env` and Google service account JSON credentials out of
    version control.
-   Use read-only Google Sheets API access when the application only
    needs to import data.
-   Share each spreadsheet only with the service account that needs
    access.
-   Treat spreadsheet values and mapping configuration as untrusted
    input.
-   Validate destination identifiers and enforce an explicit policy for
    which database tables and columns may be managed.
-   Restrict mapping-management functionality to authorized users.
-   Do not expose credentials, private keys, or sensitive spreadsheet
    contents in application logs.
-   Use a dedicated database user with only the privileges required by
    the application.

------------------------------------------------------------------------

## Project status

**Completed through Stage 4:**

-   [x] Laravel project and local development environment setup
-   [x] MySQL database configuration
-   [x] Initial database schema and Eloquent models
-   [x] Example spreadsheet and mapping seed data
-   [x] Google Sheets API and service account configuration
-   [x] Google Sheets metadata and tab data retrieval
-   [x] Dynamic header-based column mapping
-   [x] Value validation and type conversion
-   [x] One-to-many source-column mapping
-   [x] Tinker-based testing

**The current milestone produces validated, structured records from
configured Google Sheets.**
