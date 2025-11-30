# CEP Query Service

A PHP library for querying Banco de México's CEP (Comprobantes Electrónicos de Pago / Electronic Payment Receipts) system using Guzzle HTTP client.

## Overview

This library provides a simple interface to query the SPEI payment system through Banco de México's CEP website. It uses Guzzle HTTP client to make direct API requests to the CEP system.

## Features

- ✅ Query payment status using tracking key or reference number
- ✅ Download payment files (XML, PDF, ZIP)
- ✅ Extract detailed payment information from XML (RFC, CURP, names)
- ✅ Retrieve available bank options from CEP system
- ✅ Automatic form validation and data sanitization
- ✅ Lightweight HTTP-based approach (no browser required)
- ✅ Comprehensive error handling and logging
- ✅ Framework-agnostic with Laravel integration
- ✅ Date format normalization
- ✅ Bank code lookup by name

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- Guzzle HTTP client (installed automatically via composer)

## Installation

### For Laravel Projects

1. The package is available in composer. Run composer install:

```bash
composer require carlosupreme/cep-query-payment
```

2. The service provider is already registered via Laravel's package auto-discovery.

3. Run:

```bash
composer dump-autoload
```

## Usage

### Basic Usage (Framework-Agnostic)

```php
use Carlosupreme\CEPQueryPayment\CEPQueryService;

// Create service instance
$cepService = new CEPQueryService();

// Prepare form data
$formData = [
    'fecha' => '15-01-2024',           // Payment date (dd-mm-yyyy)
    'tipoCriterio' => 'T',             // 'T' for tracking key, 'R' for reference
    'criterio' => '1234567890',        // Tracking key or reference number
    'emisor' => '40012',               // Sender bank code
    'receptor' => '40002',             // Receiver bank code
    'cuenta' => '012345678901234567',  // Beneficiary CLABE (18 digits)
    'monto' => '1500.00',              // Amount
];

// Query payment
try {
    $result = $cepService->queryPayment($formData);

    if ($result !== null) {
        // Payment found
        print_r($result);
    } else {
        // Payment not found
        echo "Payment not found";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Laravel Usage

```php
use Carlosupreme\CEPQueryPayment\CEPQueryService;

class PaymentController extends Controller
{
    public function __construct(
        private CEPQueryService $cepService
    ) {}

    public function verifyPayment(Request $request)
    {
        $formData = [
            'fecha' => CEPQueryService::formatDate($request->payment_date),
            'tipoCriterio' => 'T',
            'criterio' => $request->tracking_key,
            'emisor' => $request->sender_bank,
            'receptor' => $request->receiver_bank,
            'cuenta' => $request->clabe,
            'monto' => $request->amount,
        ];

        $result = $this->cepService->queryPayment($formData);

        return response()->json([
            'found' => $result !== null,
            'data' => $result,
        ]);
    }

    public function getPaymentDetails(Request $request)
    {
        $formData = [
            'fecha' => CEPQueryService::formatDate($request->payment_date),
            'tipoCriterio' => 'T',
            'criterio' => $request->tracking_key,
            'emisor' => $request->sender_bank,
            'receptor' => $request->receiver_bank,
            'cuenta' => $request->clabe,
            'monto' => $request->amount,
        ];

        try {
            $details = $this->cepService->getPaymentDetails($formData);

            return response()->json([
                'success' => true,
                'sender' => $details['sender'],
                'beneficiary' => $details['beneficiary'],
                'operation' => $details['operation'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function downloadPaymentFile(Request $request)
    {
        $formData = [
            'fecha' => CEPQueryService::formatDate($request->payment_date),
            'tipoCriterio' => 'T',
            'criterio' => $request->tracking_key,
            'emisor' => $request->sender_bank,
            'receptor' => $request->receiver_bank,
            'cuenta' => $request->clabe,
            'monto' => $request->amount,
        ];

        $format = $request->format ?? 'PDF'; // XML, PDF, or ZIP

        try {
            $content = $this->cepService->downloadPaymentFile($formData, $format);

            $mimeTypes = [
                'XML' => 'application/xml',
                'PDF' => 'application/pdf',
                'ZIP' => 'application/zip',
            ];

            $extensions = [
                'XML' => 'xml',
                'PDF' => 'pdf',
                'ZIP' => 'zip',
            ];

            return response($content)
                ->header('Content-Type', $mimeTypes[$format])
                ->header('Content-Disposition', "attachment; filename=payment.{$extensions[$format]}");
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
```

### Get Available Banks

```php
$banks = $cepService->getBankOptions();

// Returns array like:
// [
//     '40002' => 'BANAMEX',
//     '40012' => 'BBVA BANCOMER',
//     ...
// ]
```

### Bank Code Lookup

```php
// Find bank code by name (case-insensitive)
$bankCode = $cepService->getBankCodeByName('BBVA');
// Returns: '40012'
```

### Download Payment Files

The service supports downloading payment files in XML, PDF, or ZIP formats:

```php
$formData = [
    'fecha' => '27-11-2025',
    'tipoCriterio' => 'T',
    'criterio' => 'NU395279IIKO8NC89E0DJFW89UJ',
    'emisor' => '90638',
    'receptor' => '90722',
    'cuenta' => '722969013421061131',
    'monto' => '75',
];

// Download XML file
$xmlContent = $cepService->downloadPaymentFile($formData, 'XML');
file_put_contents('payment.xml', $xmlContent);

// Download PDF file
$pdfContent = $cepService->downloadPaymentFile($formData, 'PDF');
file_put_contents('payment.pdf', $pdfContent);

// Download ZIP file
$zipContent = $cepService->downloadPaymentFile($formData, 'ZIP');
file_put_contents('payment.zip', $zipContent);
```

### Extract Payment Details from XML

Get detailed payment information including RFC, CURP, and full names:

```php
$formData = [
    'fecha' => '27-11-2025',
    'tipoCriterio' => 'T',
    'criterio' => 'NU395279IIKO8NC89JFSOIF89JF',
    'emisor' => '90638',
    'receptor' => '90722',
    'cuenta' => '722969013421061131',
    'monto' => '75',
];

$details = $cepService->getPaymentDetails($formData);

// Returns structured array:
// [
//     'operation' => [
//         'date' => '2025-11-27',
//         'time' => '11:47:12',
//         'spei_key' => '90722',
//         'tracking_key' => 'NUJUWIFJCUIWFJUIWJU39JO',
//         'certificate_num' => '292099320392930239',
//     ],
//     'beneficiary' => [
//         'bank' => 'Mercado Pago W',
//         'name' => 'Jorge Navarro Escobedo',
//         'account_type' => '40',
//         'account' => '7229690134668767678',
//         'rfc' => 'EONJ700428H8UY',
//         'curp' => null, // If available in XML
//         'concept' => 'Transferencia',
//         'iva' => '0.00',
//         'amount' => '75',
//     ],
//     'sender' => [
//         'bank' => 'NU MEXICO',
//         'name' => 'JUAN VARGAS',
//         'account_type' => '40',
//         'account' => '623903902338908908',
//         'rfc' => 'VAGJ991203NXK9',
//         'curp' => null, // If available in XML
//     ],
// ]

// Access specific details
echo "Sender: " . $details['sender']['name'];
echo "Sender RFC: " . $details['sender']['rfc'];
echo "Beneficiary: " . $details['beneficiary']['name'];
echo "Beneficiary RFC: " . $details['beneficiary']['rfc'];
echo "Amount: " . $details['beneficiary']['amount'];
```

### Parse XML Directly

If you already have XML content, you can parse it directly:

```php
$xmlContent = '<?xml version="1.0" encoding="UTF-8"?>...';
$details = $cepService->parsePaymentXml($xmlContent);
```

### Date Formatting

```php
use Carlosupreme\CEPQueryPayment\CEPQueryService;

// From DateTime object
$date = new DateTime('2024-01-15');
$formatted = CEPQueryService::formatDate($date);
// Returns: '15-01-2024'

// From Y-m-d string
$formatted = CEPQueryService::formatDate('2024-01-15');
// Returns: '15-01-2024'

// From dd/mm/yyyy
$formatted = CEPQueryService::formatDate('15/01/2024');
// Returns: '15-01-2024'
```

### Custom Logger

```php
use Carlosupreme\CEPQueryPayment\CEPQueryService;

$logger = function(string $level, string $message, array $context = []) {
    // Custom logging implementation
    error_log("[$level] $message " . json_encode($context));
};

$cepService = new CEPQueryService(null, $logger);
```

### Timeout Options

```php
$options = [
    'timeout' => 60,  // 60 second timeout
];

$result = $cepService->queryPayment($formData, $options);
```

## Form Data Structure

### Required Fields

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `fecha` | string | Payment date in dd-mm-yyyy format | `'15-01-2024'` |
| `tipoCriterio` | string | Search criteria type: 'T' (tracking) or 'R' (reference) | `'T'` |
| `criterio` | string | Tracking key (max 30) or reference (max 7) | `'1234567890'` |
| `emisor` | string | Sender bank code (numeric) | `'40012'` |
| `receptor` | string | Receiver bank code (numeric) | `'40002'` |
| `cuenta` | string | Beneficiary CLABE account (18 digits) | `'012345678901234567'` |
| `monto` | string | Payment amount | `'1500.00'` |

### Validation Rules

- **fecha**: Must be dd-mm-yyyy or dd/mm/yyyy format
- **tipoCriterio**: Must be 'T' or 'R'
- **criterio**: Max 30 chars for tracking key, max 7 for reference
- **emisor/receptor**: Must be numeric bank codes
- **cuenta**: Must be 18 digits for CLABE format
- **monto**: Must be numeric (commas allowed)

### Optional Fields for File Downloads

When using `downloadPaymentFile()` or `getPaymentDetails()`, you can optionally include:

| Field | Type | Description | Default |
|-------|------|-------------|---------|
| `receptorParticipante` | integer | Receiver participant flag | `0` |
| `tipoConsulta` | integer | Query type flag | `1` |

## Response Format

### Successful Query (Payment Found)

```php
[
    'type' => 'table',
    'headers' => ['Header1', 'Header2', ...],
    'rows' => [
        ['value1', 'value2', ...],
        ['value1', 'value2', ...],
    ]
]
```

### Payment Not Found

```php
[
    'type' => 'text',
    'content' => 'Operación no encontrada...',
    'html' => '<div>...</div>'
]
```

### No Result

```php
null
```

### Payment Details Response (getPaymentDetails)

```php
[
    'operation' => [
        'date' => '2025-11-27',
        'time' => '11:47:12',
        'spei_key' => '90722',
        'tracking_key' => 'NU395279IIKO8NC89E0DJSFIJFS89`',
        'certificate_num' => '00001000000515807241',
    ],
    'beneficiary' => [
        'bank' => 'Mercado Pago W',
        'name' => 'Javier Hernandez',
        'account_type' => '40',
        'account' => '920092390239023902',
        'rfc' => 'JLFKSD8JJMJK3',
        'curp' => null,
        'concept' => 'Transferencia',
        'iva' => '0.00',
        'amount' => '75',
    ],
    'sender' => [
        'bank' => 'NU MEXICO',
        'name' => 'JUAN VARGAS',
        'account_type' => '40',
        'account' => '239840989208490320',
        'rfc' => 'JIF983JIUFJI',
        'curp' => null,
    ],
]
```

### File Download Response (downloadPaymentFile)

Returns raw file content as a string:
- **XML**: UTF-8 encoded XML string
- **PDF**: Binary PDF content
- **ZIP**: Binary ZIP file content

## Error Handling

The library throws `Exception` for various error conditions:

```php
try {
    $result = $cepService->queryPayment($formData);
} catch (Exception $e) {
    // Handle errors:
    // - Missing required fields
    // - Invalid data format
    // - HTTP request failures
    // - Timeout errors
    // - Invalid response format

    echo "Error: " . $e->getMessage();
}
```

Common exceptions:

- `Required field missing: {field}`
- `Invalid tipoCriterio. Must be 'T' or 'R'`
- `Invalid date format. Use dd-mm-yyyy or dd/mm/yyyy`
- `Invalid CLABE format. Must be 18 digits`
- `Invalid format. Must be 'XML', 'PDF', or 'ZIP'`
- `Failed to parse XML: {errors}`
- `CEP HTTP request failed: {message}`
- `Failed to download {format} file: {message}`

## Configuration

### Timeout

Default timeout is 60 seconds.

### Custom HTTP Client

You can provide a custom Guzzle HTTP client:

```php
use GuzzleHttp\Client;
use Carlosupreme\CEPQueryPayment\CEPQueryService;

$httpClient = new Client([
    'timeout' => 120,
    'verify' => true,
]);

$cepService = new CEPQueryService($httpClient);
```

## Security Considerations

- Form data is sanitized before logging (sensitive fields are masked)
- CLABE accounts show only last 4 digits in logs
- Tracking keys/references show only last 3 characters in logs
- Use environment variables for sensitive configuration
- Consider rate limiting to avoid overloading CEP system

## Performance Tips

1. **Cache Bank Options**: Bank codes rarely change, cache them
2. **Adjust Timeouts**: Reduce for faster failures, increase for slow networks
3. **Queue Jobs**: For Laravel, use queues for CEP queries
4. **Error Monitoring**: Log failures for debugging

## Troubleshooting

### HTTP Request Fails

- Check network connectivity
- Verify CEP website is accessible
- Consider server location (latency)
- Review logs for detailed error messages

### Timeout Errors

- Increase timeout in options
- Check network connectivity
- Verify CEP website is accessible

### Invalid Response

- CEP website may have changed structure
- Verify form data is correct

### No Results Found

- Verify payment date is correct
- Check tracking key/reference number
- Ensure bank codes are correct
- Confirm CLABE account matches
- Payment may not exist in system

## Development

### Running Tests

```bash
# Install dev dependencies
composer install --dev

# Run tests
./vendor/bin/pest
```

## License

MIT License - See LICENSE file for details

## Support

For issues, questions, or contributions, please feel free to open a GitHub issue.

## Credits

Developed for Carlos Sosa.

## Changelog

### Version 1.1.0 (2025-11-30)
- Added support for downloading payment files (XML, PDF, ZIP)
- Added `downloadPaymentFile()` method
- Added `getPaymentDetails()` method to extract detailed payment information
- Added `parsePaymentXml()` method for parsing XML payment responses
- Extract RFC, CURP, and full names from both sender and beneficiary
- Enhanced documentation with file download examples
- Added Laravel controller examples for file downloads

### Version 1.0.0 (2025)
- Initial release
- Support for payment queries
- Bank options retrieval
- Laravel integration
- Comprehensive validation
