# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel-friendly (but framework-agnostic at its core) PHP package that queries Banco de México's CEP system
(Comprobantes Electrónicos de Pago) to verify SPEI transfers. It talks to `https://www.banxico.org.mx/cep/`
over plain HTTP with Guzzle and scrapes the HTML/XML/JSON responses — there is no public API and no browser
automation (an earlier Puppeteer implementation was replaced; some prose in `ARCHITECTURE.md` still reflects
the older design).

Namespace: `Carlosupreme\CEPQueryPayment\` → `src/`. Tests: `Carlosupreme\CEPQueryPayment\Tests\` → `tests/`.

## Commands

```bash
composer install              # vendor/ is gitignored and not present by default
composer test                 # vendor/bin/pest
composer test-coverage        # vendor/bin/pest --coverage
composer format               # vendor/bin/pint (Laravel Pint, default preset)

vendor/bin/pest tests/SomeTest.php          # single file
vendor/bin/pest --filter "partial name"     # single test by name

php examples/basic-usage.php  # live end-to-end script; hits the real Banxico site
```

Tests use Pest 3 with Orchestra Testbench. `tests/Pest.php` binds `tests/TestCase.php` to everything under
`tests/`, and `TestCase::getPackageProviders()` boots `CEPQueryServiceProvider`. There are currently **no
actual test files** — only the harness. New tests should mock Guzzle (`MockHandler`/`HandlerStack`) and inject
the client via the `CEPQueryService` constructor rather than hitting Banxico.

## Architecture

Effectively one class: `src/CEPQueryService.php` (~540 lines). Everything else is Laravel wiring.

**Banxico endpoints used** (all relative to `https://www.banxico.org.mx`, hardcoded in `$baseUri`):

| Endpoint | Method | Purpose |
|---|---|---|
| `/cep/` | GET | Session warm-up — populates the `CookieJar` before every real call |
| `/cep/valida.do` | POST form | Payment lookup (`tipoConsulta=0`) **and** CEP-mode session setup (`tipoConsulta=1`) |
| `/cep/instituciones.do?fecha=` | GET | Bank list as JSON (`instituciones` = array of `[id, name]` pairs) |
| `/cep/descarga.do?formato=` | GET | XML/PDF/ZIP receipt download; **session-driven, takes only `formato`** |

The cookie warm-up is mandatory: `valida.do` and `descarga.do` reject requests without a session cookie from
`/cep/`. Requests also carry browser-mimicking headers (`User-Agent`, `X-Requested-With`, `Origin`, `Referer`,
`Sec-Fetch-*`) — dropping these breaks the calls even though nothing in the code depends on them locally.

**`descarga.do` is stateful and this is the whole trick.** It accepts no payment parameters of its own — it
serves whatever CEP the *session* is holding. You must POST `valida.do` with `tipoConsulta=1` first ("Descargar
CEP" mode); that response is a small page of `descarga.do?formato=PDF|XML|ZIP` links and is the signal the CEP
is ready. Calling `descarga.do` without that POST returns a **500**, not a 4xx. It is also GET-only — POST
returns 405. `tipoConsulta=0` is the ordinary status query and does *not* arm the download.

There is a second, unrelated download on the `tipoConsulta=0` results page: a POST to
`descargaComprobanteSPEI.do` with `htmlCEPImprimir` set to the client-side inner HTML of `#consultaMISPEI`. It
returns `Reporte_Estado_De_Pago.pdf`, a status report the page explicitly says is *not* a CEP. Don't confuse it
with the real receipt.

Banxico throttles with a captcha: several full query+download cycles in quick succession start returning
`La imagen de seguridad no fue ingresada correctamente`. Space out live tests by ~30-60s.

**Call flow.** `queryPayment()` and `downloadPaymentFile()` each: validate → warm up cookies → build the
`$payload` array → send → parse. `getPaymentDetails()` is `downloadPaymentFile(..., 'XML')` piped into
`parsePaymentXml()`. `getBankCodeByName()` re-fetches the whole bank list on every call (no caching).

**Parsing is intentionally defensive** because the upstream HTML is not a contract:

- `parseHtmlResponse()` walks a DOMXPath fallback chain (`#consultaMISPEI .styled-table` → any table in
  `#consultaMISPEI` → any `//table`) and, if no usable table/rows are found, degrades to
  `['type' => 'text', 'content' => ...]`. Callers must handle `type` being `table` *or* `text`, plus `null`.
- Table rows are emitted as `['label' => ..., 'value' => ...]` pairs, not positional columns.
- `parsePaymentXml()` reads Banxico's XML **attributes** (`FechaOperacion`, `ClaveSPEI`, `Beneficiario[...]`,
  `Ordenante[...]`) and maps them to English keys (`operation`/`beneficiary`/`sender`).

**Validation** (`validateFormData()`) takes `$formData` **by reference** and normalizes `fecha` in place
(`dd/mm/yyyy` → `dd-mm-yyyy`). It enforces the seven required fields, `tipoCriterio` ∈ `{T, R}` with length
caps (30 for tracking key, 7 for reference), 18-digit CLABE, numeric bank codes and amount. All failures are
plain `Exception`s with Spanish-domain messages — there are no custom exception types.

**Logging** is an injected `callable(string $level, string $message, array $context)`, not PSR-3 and not
Laravel's `Log`. It defaults to a no-op. `sanitizeLogData()` masks `cuenta` (last 4) and `criterio` (last 3)
before anything is logged — preserve that when adding log calls that touch form data.

## Known inconsistencies

Be aware of these before "fixing" something that looks broken; they are real but pre-existing.

- **Two service providers.** `CEPQueryServiceProvider` is the one registered for auto-discovery in
  `composer.json` and used by tests; it constructs `new CEPQueryService()` with no arguments.
  `CEPQueryPaymentServiceProvider` is dead code — nothing registers it, and it passes the config array into a
  constructor whose first parameter is `?GuzzleHttp\Client`.
- **`config/cep-query-payment.php` is not wired up.** Only the dead provider merges it. `BANXICO_CEP_URL` and
  `BANXICO_CEP_TIMEOUT` (30000, in ms) are ignored; the service hardcodes `$baseUri` and a 60-**second**
  timeout. The `timeout` option passed to `queryPayment()`/`downloadPaymentFile()` is in seconds.
- **Undeclared dependencies.** `guzzlehttp/guzzle` is used throughout but absent from `composer.json`
  require (it resolves transitively). `use function Symfony\Component\Clock\now;` at the top of
  `CEPQueryService.php` is unused and `symfony/clock` is not required either; `symfony/process` is required
  but no longer used since the Puppeteer removal.
- **README drift.** `getBankOptions()` is documented as returning `['40002' => 'BANAMEX', ...]` but actually
  returns `[['id' => ..., 'name' => ...], ...]`. The documented "payment not found" shape includes an `html`
  key the parser never emits.
- The old `TODO wtf why is this a GET request?` on `descarga.do` is resolved: the endpoint is GET-only by
  design because it reads the CEP from the session. See the download flow above.

## Conventions

Code style is Pint's default (Laravel preset) except that this codebase puts opening braces on the **same
line as method signatures** (`public function queryPayment(...): ?array {`). Running `composer format` will
reformat those — check the diff before committing wholesale style changes.
