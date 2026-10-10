<?php

use Carlosupreme\CEPQueryPayment\CEPQueryService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

const SAMPLE_CEP = '<?xml version="1.0" encoding="UTF-8"?><SPEI_Tercero FechaOperacion="2026-10-09" sello="AAAA" numeroCertificado="00001000000000000001"/>';

function validatorService(array $responses, array &$history = []): CEPQueryService {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new CEPQueryService(new Client(['handler' => $stack, 'base_uri' => 'https://www.banxico.org.mx']));
}

function validatorFixture(): string {
    return file_get_contents(__DIR__ . '/Fixtures/validador-resultado.html');
}

it('validates a batch and maps results back to the caller keys in order', function () {
    $history = [];
    $service = validatorService([new Response(200, [], validatorFixture())], $history);

    $result = $service->validateCepXmlBatch(['genuine' => SAMPLE_CEP, 'tampered' => SAMPLE_CEP]);

    expect($result['summary'])->toBe(['total' => 2, 'valid' => 1, 'invalid' => 1])
        ->and(array_keys($result['results']))->toBe(['genuine', 'tampered'])
        ->and($result['results']['genuine'])->toBe([
            'valid'          => true,
            'note'           => 'Comprobante Electrónico de Pago Válido',
            'original_chain' => '||1|09102026|GENUINE|',
            'seal'           => 'AAAA',
            'certificate'    => '00001000000000000001',
        ])
        ->and($result['results']['tampered']['valid'])->toBeFalse()
        ->and($result['results']['tampered']['note'])->toBe('Comprobante Electrónico de Pago con información errónea');

    $request = $history[0]['request'];
    $body = (string)$request->getBody();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/validador-cep-spei/Validador')
        ->and($body)->toContain('name="file[0]"; filename="cep-0.xml"')
        ->and($body)->toContain('name="file[1]"; filename="cep-1.xml"');
});

it('rejects malformed XML before calling Banxico', function () {
    $history = [];
    $service = validatorService([], $history);

    expect(fn () => $service->validateCepXmlBatch(['ok' => SAMPLE_CEP, 'broken' => 'not xml']))
        ->toThrow(Exception::class, 'CEP XML [broken] is not well-formed XML')
        ->and($history)->toBeEmpty();
});

it('rejects empty and oversized batches', function () {
    $service = validatorService([]);

    expect(fn () => $service->validateCepXmlBatch([]))->toThrow(Exception::class, 'At least one CEP XML is required')
        ->and(fn () => $service->validateCepXmlBatch(array_fill(0, 101, SAMPLE_CEP)))->toThrow(Exception::class, 'at most 100');
});

it('reports Banxico format errors against the caller key', function () {
    $html = '<html><body><h2>ERROR</h2>Ocurri&oacute; un error al procesar la solicitud. Error en el formato del archivo: cep-0.xml, verifique!</body></html>';
    $service = validatorService([new Response(500, [], $html)]);

    expect(fn () => $service->validateCepXmlBatch(['invoice-42' => SAMPLE_CEP]))
        ->toThrow(Exception::class, 'Error en el formato del archivo: [invoice-42], verifique!');
});

it('fails closed when the page does not add up', function () {
    // Summary claims both are valid, but only one row carries the checkbox.
    $html = str_replace('<td>1</td>
                            <td>1</td>', '<td>2</td>
                            <td>0</td>', validatorFixture());

    $service = validatorService([new Response(200, [], $html)]);

    expect(fn () => $service->validateCepXmlBatch([SAMPLE_CEP, SAMPLE_CEP]))
        ->toThrow(Exception::class, 'Unexpected CEP validator response');
});

it('fails closed when the validity marker is unrecognized', function () {
    $html = str_replace('<center>X</center>', '<center>?</center>', validatorFixture());
    $service = validatorService([new Response(200, [], $html)]);

    expect(fn () => $service->validateCepXmlBatch([SAMPLE_CEP, SAMPLE_CEP]))
        ->toThrow(Exception::class, 'unrecognized validity marker');
});

it('validates a single CEP', function () {
    $html = str_replace(
        ['<td>2</td>', '<center>cep-1.xml </center>'],
        ['<td>1</td>', '<center>cep-9.xml </center>'],
        validatorFixture()
    );
    // Drop the invalid row so the page describes exactly one file. The onclick payload
    // contains its own </tr>, so cut up to the next row instead.
    $html = preg_replace('#<tr>\s*<td>\s*<center>cep-9\.xml.*?(?=<tr>\s*<td>\s*<center>cep-0\.xml)#s', '', $html);
    $html = str_replace('<td>1</td>
                            <td>1</td>
                            <td>1</td>', '<td>1</td>
                            <td>1</td>
                            <td>0</td>', $html);

    $service = validatorService([new Response(200, [], $html)]);

    expect($service->validateCepXml(SAMPLE_CEP)['valid'])->toBeTrue();
});
