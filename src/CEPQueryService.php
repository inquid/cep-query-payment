<?php

namespace Carlosupreme\CEPQueryPayment;

use Carbon\Carbon;
use DateTime;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

use RuntimeException;
use function Symfony\Component\Clock\now;

class CEPQueryService
{
    // Banxico's validator advertises its file limit via GET /validador-cep-spei/Validador
    // ("100#<privacy notice url>") and caps each file at 1 MB in its upload widget.
    private const VALIDATOR_MAX_FILES = 100;

    private const VALIDATOR_MAX_FILE_BYTES = 1024 * 1024;

    private Client $http;

    private int $timeout;

    private array $defaultOptions;

    /** @var callable|null */
    private $logger;

    private string $baseUri = 'https://www.banxico.org.mx';

    private string $timezone;

    public function __construct(?Client $httpClient = null, ?callable $logger = null, string $timezone = 'America/Mexico_City') {
        $this->timeout = 60;
        $this->timezone = $timezone;
        $this->defaultOptions = [
            'timeout' => $this->timeout,
        ];

        $this->http = $httpClient ?? new Client([
            'base_uri' => $this->baseUri,
            'timeout'  => $this->timeout,
            'verify'   => true,
            'headers'  => [
                'User-Agent'       => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                'Accept'           => '*/*',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);

        $this->logger = $logger;
    }

    /**
     * Query CEP using form data via direct POST to Banxico.
     *
     * @param array $formData
     * @param array $options (optional timeout override, etc.)
     * @return array|null
     *
     * @throws Exception
     */
    public function queryPayment(array $formData, array $options = []): ?array {
        $this->validateFormData($formData);

        $timeout = $options['timeout'] ?? $this->timeout;

        $jar = new CookieJar();

        try {
            // Warm up session (cookies, etc.)
            $this->http->get('/cep/', [
                'cookies' => $jar,
                'timeout' => $timeout,
            ]);

            $payload = [
                'captcha'              => '',
                'criterio'             => $formData['criterio'],
                'cuenta'               => $formData['cuenta'],
                'emisor'               => $formData['emisor'],
                'fecha'                => $formData['fecha'],
                'monto'                => $formData['monto'],
                'receptor'             => $formData['receptor'],
                'receptorParticipante' => 0,
                'tipoConsulta'         => 0,
                'tipoCriterio'         => $formData['tipoCriterio'],
            ];

            $this->log('debug', 'Sending CEP request', [
                'payload' => $this->sanitizeLogData($payload),
            ]);

            $response = $this->http->post('/cep/valida.do', [
                'cookies'     => $jar,
                'timeout'     => $timeout,
                'headers'     => [
                    'Content-Type'   => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Origin'         => $this->baseUri,
                    'Referer'        => $this->baseUri . '/cep/',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Dest' => 'empty',
                ],
                'form_params' => $payload,
            ]);

            $html = (string)$response->getBody();

            $this->log('debug', 'Raw CEP response (truncated)', [
                'html' => mb_substr($html, 0, 2000),
            ]);

            $parsed = $this->parseHtmlResponse($html);

            $this->log('info', 'CEP response parsed', [
                'has_data'  => $parsed !== null,
                'data_type' => $parsed['type'] ?? 'null',
            ]);

            return $parsed;
        } catch (GuzzleException $e) {
            $this->log('error', 'CEP HTTP request failed', [
                'error'    => $e->getMessage(),
                'formData' => $this->sanitizeLogData($formData),
            ]);

            throw new Exception('CEP HTTP request failed: ' . $e->getMessage(), 0, $e);
        } catch (Exception $e) {
            $this->log('error', 'CEP Query failed', [
                'error'    => $e->getMessage(),
                'formData' => $this->sanitizeLogData($formData),
            ]);

            throw $e;
        }
    }

    /**
     * Get available bank options by scraping the CEP page.
     * TODO: Load them using the instituciones.do endpoint if possible.
     *
     * @return array
     *
     * @throws Exception
     */
    public function getBankOptions(): array {
        $date = Carbon::now($this->timezone)
                      ->subDay()
                      ->format('d-m-Y');

        try {
            $response = $this->http->get('/cep/instituciones.do', [
                'query'   => ['fecha' => $date],
                'headers' => [
                    'Accept'     => 'application/json, text/javascript, */*; q=0.01',
                    'User-Agent' => 'Mozilla/5.0',
                ],
                'timeout' => $this->timeout,
            ]);

            $raw = (string)$response->getBody();

            $this->log('debug', 'Bank options raw page (truncated)', [
                'json' => mb_substr($raw, 0, 2000),
            ]);

            $data = json_decode($raw, true);

            if (!isset($data['instituciones']) || !is_array($data['instituciones'])) {
                throw new Exception("Invalid instituciones format");
            }

            $banks = array_map(function ($item) {
                return [
                    'id'   => $item[0],
                    'name' => $item[1],
                ];
            }, $data['instituciones']);

            return $banks;
        } catch (GuzzleException $e) {
            $this->log('error', 'Failed to get bank options', ['error' => $e->getMessage()]);
            throw new Exception('Failed to get bank options: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate form data.
     *
     * @throws Exception
     */
    private function validateFormData(array &$formData): void {
        $required = ['fecha', 'tipoCriterio', 'criterio', 'emisor', 'receptor', 'cuenta', 'monto'];

        foreach ($required as $field) {
            if (!isset($formData[$field]) || $formData[$field] === '') {
                throw new Exception("Required field missing: {$field}");
            }
        }

        if (!in_array($formData['tipoCriterio'], ['T', 'R'], true)) {
            throw new Exception("Invalid tipoCriterio. Must be 'T' (tracking key) or 'R' (reference number)");
        }

        if ($formData['tipoCriterio'] === 'R' && strlen($formData['criterio']) > 7) {
            throw new Exception('Reference number cannot exceed 7 characters');
        }

        if ($formData['tipoCriterio'] === 'T' && strlen($formData['criterio']) > 30) {
            throw new Exception('Tracking key cannot exceed 30 characters');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $formData['fecha'])) {
            $formData['fecha'] = str_replace('/', '-', $formData['fecha']);
        } else if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $formData['fecha'])) {
            throw new Exception('Invalid date format. Use dd-mm-yyyy or dd/mm/yyyy');
        }

        if (isset($formData['cuenta']) && strlen($formData['cuenta']) === 18 && !preg_match('/^\d{18}$/', $formData['cuenta'])) {
            throw new Exception('Invalid CLABE format. Must be 18 digits');
        }

        if (!is_numeric(str_replace(',', '', (string)$formData['monto']))) {
            throw new Exception('Invalid amount format');
        }

        if (!is_numeric($formData['emisor']) || !is_numeric($formData['receptor'])) {
            throw new Exception('Invalid bank codes. Must be numeric');
        }
    }

    /**
     * Parse CEP HTML response into a structured array.
     */
    private function parseHtmlResponse(string $html): ?array {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');

        if (!$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $content = trim(strip_tags($html));
            return $content !== '' ? ['type' => 'text', 'content' => $content] : null;
        }

        $xpath = new DOMXPath($dom);

        // Specific table with payment info (matches sample HTML)
        $table = $xpath->query("//div[@id='consultaMISPEI']//table[@id='xxx' or contains(@class,'styled-table')]")
                       ->item(0)
            ?: $xpath->query("//div[@id='consultaMISPEI']//table")->item(0)
                ?: $xpath->query('//table')->item(0);

        if (!$table instanceof DOMElement) {
            $text = trim($xpath->evaluate('string(//div[@id="consultaMISPEI"] | //div[@class="cuerpo-msg"] | //body)'));

            return [
                'type'    => 'text',
                'content' => $text !== '' ? $text : trim(strip_tags($html)),
            ];
        }

        $rows = [];

        // Each row: <tr><td>Label</td><td>Value</td></tr>
        $rowNodes = $xpath->query('.//tbody//tr', $table);
        if (!$rowNodes || $rowNodes->length === 0) {
            $rowNodes = $xpath->query('.//tr', $table);
        }

        foreach ($rowNodes as $rowNode) {
            /** @var DOMElement $rowNode */
            $cellNodes = $xpath->query('.//td|.//th', $rowNode);
            if ($cellNodes->length < 2) {
                continue;
            }

            $label = trim($cellNodes->item(0)->textContent ?? '');
            $value = trim($cellNodes->item(1)->textContent ?? '');

            if ($label === '' && $value === '') {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        if ($rows === []) {
            $text = trim($xpath->evaluate('string(//div[@id="consultaMISPEI"] | //div[@class="cuerpo-msg"] | //body)'));

            return [
                'type'    => 'text',
                'content' => $text !== '' ? $text : trim(strip_tags($html)),
            ];
        }

        // Summary above the table
        $summary = trim($xpath->evaluate(
            'string(//div[@id="consultaMISPEI"]//div[contains(@class,"info")]/center/strong)'
        ));

        return [
            'type'    => 'table',
            'summary' => $summary !== '' ? $summary : null,
            'headers' => ['label', 'value'],
            'rows'    => $rows,
        ];
    }

    /**
     * Sanitize form data for logging (mask sensitive information).
     */
    private function sanitizeLogData(array $formData): array {
        $sanitized = $formData;

        if (isset($sanitized['cuenta'])) {
            $sanitized['cuenta'] = '***' . substr($sanitized['cuenta'], -4);
        }

        if (isset($sanitized['criterio'])) {
            $sanitized['criterio'] = '***' . substr($sanitized['criterio'], -3);
        }

        return $sanitized;
    }

    /**
     * Format date for CEP form (dd-mm-yyyy).
     *
     * @param string|DateTime $date
     */
    public static function formatDate($date): string {
        if ($date instanceof DateTime) {
            return $date->format('d-m-Y');
        }

        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        if ($dateTime) {
            return $dateTime->format('d-m-Y');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return str_replace('/', '-', $date);
        }

        return $date;
    }

    /**
     * Get bank code by name (case-insensitive search).
     *
     * @throws Exception
     */
    public function getBankCodeByName(string $bankName): ?string {
        $banks = $this->getBankOptions();
        $bankName = strtolower(trim($bankName));

        foreach ($banks as $bank) {
            if (str_contains(strtolower($bank['name']), $bankName)) {
                return $bank['id'];
            }
        }

        return null;
    }

    /**
     * Download payment file (XML, PDF, or ZIP format).
     *
     * @param array $formData Same form data as queryPayment
     * @param string $format 'XML', 'PDF', or 'ZIP'
     * @param array $options Optional timeout override
     * @return string Raw file content
     *
     * @throws Exception
     */
    public function downloadPaymentFile(array $formData, string $format = 'XML', array $options = []): string {
        $this->validateFormData($formData);

        $format = strtoupper($format);
        if (!in_array($format, ['XML', 'PDF', 'ZIP'], true)) {
            throw new Exception("Invalid format. Must be 'XML', 'PDF', or 'ZIP'");
        }

        $timeout = $options['timeout'] ?? $this->timeout;
        $jar = new CookieJar();

        try {
            // Warm up session
            $this->http->get('/cep/', [
                'cookies' => $jar,
                'timeout' => $timeout,
            ]);

            // tipoConsulta = 1 puts the session in "Descargar CEP" mode. This POST is what
            // loads the CEP into the server-side session; descarga.do reads it from there and
            // ignores any parameters of its own besides `formato`. Skipping it makes
            // descarga.do return a 500.
            $payload = [
                'captcha'              => '',
                'criterio'             => $formData['criterio'],
                'cuenta'               => $formData['cuenta'],
                'emisor'               => $formData['emisor'],
                'fecha'                => $formData['fecha'],
                'monto'                => $formData['monto'],
                'receptor'             => $formData['receptor'],
                'receptorParticipante' => $formData['receptorParticipante'] ?? 0,
                'tipoConsulta'         => 1,
                'tipoCriterio'         => $formData['tipoCriterio'],
            ];

            $this->log('debug', "Downloading {$format} file", [
                'payload' => $this->sanitizeLogData($payload),
                'format'  => $format,
            ]);

            $valida = $this->http->post('/cep/valida.do', [
                'cookies'     => $jar,
                'timeout'     => $timeout,
                'headers'     => [
                    'Content-Type'     => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Origin'           => $this->baseUri,
                    'Referer'          => $this->baseUri . '/cep/',
                    'Sec-Fetch-Site'   => 'same-origin',
                    'Sec-Fetch-Mode'   => 'cors',
                    'Sec-Fetch-Dest'   => 'empty',
                ],
                'form_params' => $payload,
            ]);

            $validaHtml = (string)$valida->getBody();

            // The CEP-mode page offers descarga.do links for each format. If they are absent the
            // payment was not found, and going ahead would only produce an opaque 500.
            if (!str_contains($validaHtml, 'descarga.do?formato')) {
                $message = $this->extractReadableText($validaHtml);

                $this->log('error', 'CEP not available for download', [
                    'response' => mb_substr($message, 0, 300),
                ]);

                throw new Exception(
                    'CEP is not available for download. Banxico responded: '
                    . (($message !== '') ? mb_substr($message, 0, 300) : 'empty response')
                );
            }

            // descarga.do is GET-only (POST returns 405) and takes only the `formato` query
            // parameter; everything else comes from the session established above.
            $response = $this->http->get('/cep/descarga.do', [
                'cookies' => $jar,
                'timeout' => $timeout,
                'query'   => ['formato' => $format],
                'headers' => [
                    'Origin'         => $this->baseUri,
                    'Referer'        => $this->baseUri . '/cep/valida.do',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Dest' => 'document',
                ],
            ]);

            $content = (string)$response->getBody();

            $this->log('info', "{$format} file downloaded successfully", [
                'size'     => strlen($content),
                'filename' => $response->getHeaderLine('Content-Disposition'),
            ]);

            return $content;
        } catch (GuzzleException $e) {
            $this->log('error', 'Download file request failed', [
                'error'  => $e->getMessage(),
                'format' => $format,
            ]);

            throw new Exception("Failed to download {$format} file: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reduce a Banxico HTML page to the text a human can act on.
     *
     * strip_tags() alone keeps the contents of <script>/<style>, and Banxico inlines a large
     * stylesheet at the top of every page — so the naive version yields nothing but CSS
     * comments. Drop those blocks first and decode entities so messages such as
     * "Operación no encontrada" survive intact.
     */
    private function extractReadableText(string $html): string {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Download and parse XML payment file to extract payment details.
     *
     * @param array $formData Same form data as queryPayment
     * @param array $options Optional timeout override
     * @return array Parsed payment details with beneficiary and sender information
     *
     * @throws Exception
     */
    public function getPaymentDetails(array $formData, array $options = []): array {
        $xmlContent = $this->downloadPaymentFile($formData, 'XML', $options);

        return $this->parsePaymentXml($xmlContent);
    }

    /**
     * Parse XML payment response to extract details.
     *
     * @param string $xmlContent Raw XML content
     * @return array Structured payment details
     *
     * @throws Exception
     */
    public function parsePaymentXml(string $xmlContent): array {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new Exception('Failed to parse XML: ' . json_encode($errors));
        }

        $details = [
            'operation' => [
                'date'            => isset($xml['FechaOperacion']) ? (string)$xml['FechaOperacion'] : null,
                'time'            => isset($xml['Hora']) ? (string)$xml['Hora'] : null,
                'spei_key'        => isset($xml['ClaveSPEI']) ? (string)$xml['ClaveSPEI'] : null,
                'tracking_key'    => isset($xml['claveRastreo']) ? (string)$xml['claveRastreo'] : null,
                'certificate_num' => isset($xml['numeroCertificado']) ? (string)$xml['numeroCertificado'] : null,
            ],
            'beneficiary' => [],
            'sender'      => [],
        ];

        // Parse beneficiary (receiver)
        if (isset($xml->Beneficiario)) {
            $beneficiary = $xml->Beneficiario;
            $details['beneficiary'] = [
                'bank'         => isset($beneficiary['BancoReceptor']) ? trim((string)$beneficiary['BancoReceptor']) : null,
                'name'         => isset($beneficiary['Nombre']) ? (string)$beneficiary['Nombre'] : null,
                'account_type' => isset($beneficiary['TipoCuenta']) ? (string)$beneficiary['TipoCuenta'] : null,
                'account'      => isset($beneficiary['Cuenta']) ? (string)$beneficiary['Cuenta'] : null,
                'rfc'          => isset($beneficiary['RFC']) ? (string)$beneficiary['RFC'] : null,
                'curp'         => isset($beneficiary['CURP']) ? (string)$beneficiary['CURP'] : null,
                'concept'      => isset($beneficiary['Concepto']) ? (string)$beneficiary['Concepto'] : null,
                'iva'          => isset($beneficiary['IVA']) ? (string)$beneficiary['IVA'] : null,
                'amount'       => isset($beneficiary['MontoPago']) ? (string)$beneficiary['MontoPago'] : null,
            ];
        }

        // Parse sender (ordenante)
        if (isset($xml->Ordenante)) {
            $ordenante = $xml->Ordenante;
            $details['sender'] = [
                'bank'         => isset($ordenante['BancoEmisor']) ? trim((string)$ordenante['BancoEmisor']) : null,
                'name'         => isset($ordenante['Nombre']) ? (string)$ordenante['Nombre'] : null,
                'account_type' => isset($ordenante['TipoCuenta']) ? (string)$ordenante['TipoCuenta'] : null,
                'account'      => isset($ordenante['Cuenta']) ? (string)$ordenante['Cuenta'] : null,
                'rfc'          => isset($ordenante['RFC']) ? (string)$ordenante['RFC'] : null,
                'curp'         => isset($ordenante['CURP']) ? (string)$ordenante['CURP'] : null,
            ];
        }

        $this->log('info', 'XML payment details parsed successfully', [
            'tracking_key' => isset($details['operation']['tracking_key'])
                ? '***' . substr($details['operation']['tracking_key'], -3)
                : 'N/A',
        ]);

        return $details;
    }

    /**
     * Validate a CEP XML against Banxico's validator (validador-cep-spei).
     *
     * This checks Banxico's digital seal, i.e. that the file is a genuine, untampered CEP.
     * It does not check that the payment matches what you expected — compare the fields
     * from parsePaymentXml() against your own records for that.
     *
     * @param string $xmlContent Raw CEP XML content
     * @param array $options Optional timeout override
     * @return array{valid: bool, note: ?string, original_chain: ?string, seal: ?string, certificate: ?string}
     *
     * @throws Exception
     */
    public function validateCepXml(string $xmlContent, array $options = []): array {
        return $this->validateCepXmlBatch([$xmlContent], $options)['results'][0];
    }

    /**
     * Validate several CEP XMLs in a single request to Banxico's validator.
     *
     * @param array<array-key, string> $xmlContents Raw CEP XML contents; keys are preserved in the results
     * @param array $options Optional timeout override
     * @return array{summary: array{total: int, valid: int, invalid: int}, results: array}
     *
     * @throws Exception
     */
    public function validateCepXmlBatch(array $xmlContents, array $options = []): array {
        if ($xmlContents === []) {
            throw new Exception('At least one CEP XML is required');
        }

        if (count($xmlContents) > self::VALIDATOR_MAX_FILES) {
            throw new Exception('Banxico validates at most ' . self::VALIDATOR_MAX_FILES . ' CEPs per request');
        }

        $timeout = $options['timeout'] ?? $this->timeout;

        // Banxico reports results by filename and not in upload order, so every file gets a
        // generated unique name that maps back to its key.
        $multipart = [];
        $keysByFilename = [];

        foreach (array_values(array_keys($xmlContents)) as $i => $key) {
            $xml = $xmlContents[$key];

            if (!is_string($xml) || trim($xml) === '') {
                throw new Exception("CEP XML [{$key}] is empty");
            }

            if (strlen($xml) > self::VALIDATOR_MAX_FILE_BYTES) {
                throw new Exception("CEP XML [{$key}] exceeds Banxico's 1 MB limit");
            }

            // A malformed file makes Banxico fail the whole batch with a 500, so reject it here
            // where we can still say which one it was.
            libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($xml);
            libxml_clear_errors();

            if ($parsed === false) {
                throw new Exception("CEP XML [{$key}] is not well-formed XML");
            }

            $filename = "cep-{$i}.xml";
            $keysByFilename[$filename] = $key;

            $multipart[] = [
                'name'     => "file[{$i}]",
                'contents' => $xml,
                'filename' => $filename,
                'headers'  => ['Content-Type' => 'text/xml'],
            ];
        }

        $this->log('debug', 'Sending CEP XMLs to validator', ['count' => count($multipart)]);

        try {
            $response = $this->http->post('/validador-cep-spei/Validador', [
                'timeout'   => $timeout,
                'multipart' => $multipart,
                'headers'   => [
                    'Origin'         => $this->baseUri,
                    'Referer'        => $this->baseUri . '/validador-cep-spei/',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-Mode' => 'cors',
                    'Sec-Fetch-Dest' => 'empty',
                ],
            ]);
        } catch (BadResponseException $e) {
            // Files Banxico can't read as a CEP come back as a 500 with an explanation such as
            // "Error en el formato del archivo: cep-0.xml, verifique!".
            $message = $this->extractReadableText((string)$e->getResponse()->getBody());
            $message = strtr($message, array_map(fn ($key) => "[{$key}]", $keysByFilename));

            $this->log('error', 'CEP validator rejected the request', [
                'status'   => $e->getResponse()->getStatusCode(),
                'response' => mb_substr($message, 0, 300),
            ]);

            throw new Exception(
                'CEP validator rejected the request. Banxico responded: '
                . ($message !== '' ? mb_substr($message, 0, 300) : 'empty response'),
                0,
                $e
            );
        } catch (GuzzleException $e) {
            $this->log('error', 'CEP validator request failed', ['error' => $e->getMessage()]);

            throw new Exception('CEP validator request failed: ' . $e->getMessage(), 0, $e);
        }

        $result = $this->parseValidatorResponse((string)$response->getBody(), $keysByFilename);

        $this->log('info', 'CEP validation completed', $result['summary']);

        return $result;
    }

    /**
     * Parse the validator's results page.
     *
     * Each row holds the filename, a validity marker (a checked checkbox when the seal is
     * valid, an "X" otherwise) and a "Ver Detalle" button whose onclick carries the detail
     * table as an HTML string. A summary table above it gives Total / Válidos / Inválidos,
     * which is used to cross-check the per-row markers: this is a security check, so a
     * page we can't fully account for is an error rather than a guess.
     *
     * @throws Exception
     */
    private function parseValidatorResponse(string $html, array $keysByFilename): array {
        $dom = $this->loadHtmlUtf8($html);
        $xpath = new DOMXPath($dom);

        $counts = [];
        foreach ($xpath->query("//table[contains(@class,'formatted-table')]//tbody//tr[1]/td") as $td) {
            $counts[] = (int)trim($td->textContent);
        }

        if (count($counts) < 3) {
            throw new Exception('Unexpected CEP validator response: summary table not found');
        }

        $summary = ['total' => $counts[0], 'valid' => $counts[1], 'invalid' => $counts[2]];

        $results = [];
        foreach ($xpath->query("//table[@id='comprobantesCEP']//tbody/tr") as $row) {
            // Commented-out columns are DOM comments, not cells, so the positions are stable.
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 3) {
                continue;
            }

            $filename = trim($cells->item(0)->textContent);
            if (!array_key_exists($filename, $keysByFilename)) {
                throw new RuntimeException("Unexpected CEP validator response: unknown file '{$filename}'");
            }

            $details = $this->parseValidatorDetail($xpath, $cells->item(2));

            $hasCheckedBox = $xpath->query(".//input[@type='checkbox' and @checked]", $cells->item(1))->length > 0;
            $markedInvalid = strtoupper(trim($cells->item(1)->textContent)) === 'X';

            if ($hasCheckedBox === $markedInvalid) {
                throw new RuntimeException("Unexpected CEP validator response: unrecognized validity marker for '{$filename}'");
            }

            $results[$keysByFilename[$filename]] = [
                'valid'          => $hasCheckedBox,
                'note'           => $details['Nota'] ?? null,
                'original_chain' => $details['Cadena Original'] ?? null,
                'seal'           => $details['Sello digital'] ?? null,
                'certificate'    => $details['Certificado utilizado'] ?? null,
            ];
        }

        $validCount = count(array_filter($results, fn ($r) => $r['valid']));

        if (count($results) !== count($keysByFilename)
            || $summary['total'] !== count($results)
            || $summary['valid'] !== $validCount) {
            throw new Exception(sprintf(
                'Unexpected CEP validator response: sent %d files, summary reports %d (%d valid), parsed %d (%d valid)',
                count($keysByFilename), $summary['total'], $summary['valid'], count($results), $validCount
            ));
        }

        // Restore the caller's order; Banxico's is arbitrary.
        $ordered = [];
        foreach ($keysByFilename as $key) {
            $ordered[$key] = $results[$key];
        }

        return ['summary' => $summary, 'results' => $ordered];
    }

    /**
     * Extract the label => value pairs from a "Ver Detalle" button's onclick payload.
     */
    private function parseValidatorDetail(DOMXPath $xpath, \DOMNode $cell): array {
        $button = $xpath->query('.//button[@onclick]', $cell)->item(0);
        if (!$button instanceof DOMElement) {
            return [];
        }

        // The parser has already decoded entities in the attribute value.
        if (!preg_match("/openModalDetalleCEP\('(.*)'\)/s", $button->getAttribute('onclick'), $m)) {
            return [];
        }

        $detailXpath = new DOMXPath($this->loadHtmlUtf8('<table>' . $m[1] . '</table>'));

        $details = [];
        foreach ($detailXpath->query('//tr') as $tr) {
            $tds = $detailXpath->query('./td', $tr);
            if ($tds->length >= 2) {
                $details[trim($tds->item(0)->textContent)] = trim($tds->item(1)->textContent);
            }
        }

        return $details;
    }

    /**
     * DOMDocument::loadHTML() assumes ISO-8859-1 unless told otherwise.
     */
    private function loadHtmlUtf8(string $html): DOMDocument {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return $dom;
    }

    /**
     * Log a message using the provided logger or do nothing.
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
