<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Services;

use eseperio\verifactu\exceptions\CensusValidationException;
use eseperio\verifactu\models\CensusValidationRequest;
use eseperio\verifactu\models\CensusValidationResult;
use eseperio\verifactu\services\CensusValidationService;
use eseperio\verifactu\services\VerifactuService;
use PHPUnit\Framework\TestCase;

class CensusValidationServiceTest extends TestCase
{
    private static string $dummyCertPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$dummyCertPath = sys_get_temp_dir() . '/verifactu_test_dummy.pem';
        if (!file_exists(self::$dummyCertPath)) {
            $keyPath = sys_get_temp_dir() . '/verifactu_test_key.pem';
            $certPath = sys_get_temp_dir() . '/verifactu_test_cert.pem';
            shell_exec('openssl req -x509 -newkey rsa:2048 -keyout ' . escapeshellarg($keyPath) . ' -out ' . escapeshellarg($certPath) . ' -days 1 -nodes -subj "/CN=test" 2>/dev/null');
            file_put_contents(self::$dummyCertPath, file_get_contents($certPath) . "\n" . file_get_contents($keyPath));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset VerifactuService static state between tests.
        $refConfig = new \ReflectionProperty(VerifactuService::class, 'config');
        $refConfig->setAccessible(true);
        $refConfig->setValue(null, []);

        CensusValidationService::setClient(null);

        VerifactuService::config([
            VerifactuService::CERT_PATH_KEY => self::$dummyCertPath,
            VerifactuService::CERT_PASSWORD_KEY => '',
            VerifactuService::SOAP_ENDPOINT => 'https://example.com/soap',
            VerifactuService::QR_VERIFICATION_URL => 'https://example.com/qr',
            VerifactuService::VNIF_ENDPOINT => 'https://example.com/vnif',
        ]);
    }

    protected function tearDown(): void
    {
        CensusValidationService::setClient(null);

        $refConfig = new \ReflectionProperty(VerifactuService::class, 'config');
        $refConfig->setAccessible(true);
        $refConfig->setValue(null, []);

        parent::tearDown();
    }

    public function testValidateReturnsResultsFromParsedResponse(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');
        $request->addContribuyente('A12345678', null);

        CensusValidationService::setClient($this->createMockClient($this->getTwoResultsXml()));

        $results = CensusValidationService::validate($request);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(CensusValidationResult::class, $results[0]);
        $this->assertSame('12345678Z', $results[0]->nif);
        $this->assertSame('Juan Perez', $results[0]->nombre);
        $this->assertSame(CensusValidationResult::RESULT_IDENTIFICADO, $results[0]->resultado);
        $this->assertTrue($results[0]->isValid());

        $this->assertSame('A12345678', $results[1]->nif);
        $this->assertSame(CensusValidationResult::RESULT_NO_IDENTIFICADO, $results[1]->resultado);
        $this->assertFalse($results[1]->isValid());
    }

    public function testValidateThrowsOnInvalidRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CensusValidationService::validate(new CensusValidationRequest());
    }

    public function testValidateTranslatesSoapFaultIntoCensusValidationException(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');

        $fault = new \SoapFault('Server', 'Codigo[1101].Error en el formato del NIF');
        $mock = $this->createMock(\SoapClient::class);
        $mock->method('__soapCall')
            ->willThrowException($fault);

        CensusValidationService::setClient($mock);

        try {
            CensusValidationService::validate($request);
            $this->fail('Expected CensusValidationException');
        } catch (CensusValidationException $e) {
            $this->assertSame(1101, $e->getAeatCode());
            $this->assertStringContainsString('Codigo[1101]', $e->getFaultString());
            $this->assertInstanceOf(\SoapFault::class, $e->getPrevious());
        }
    }

    public function testValidateFaultWithoutCodeStillWrapped(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');

        $fault = new \SoapFault('Server', 'Something unexpected');
        $mock = $this->createMock(\SoapClient::class);
        $mock->method('__soapCall')
            ->willThrowException($fault);

        CensusValidationService::setClient($mock);

        try {
            CensusValidationService::validate($request);
            $this->fail('Expected CensusValidationException');
        } catch (CensusValidationException $e) {
            $this->assertNull($e->getAeatCode());
            $this->assertSame('Something unexpected', $e->getFaultString());
        }
    }

    public function testParseResponseHandlesFaultBody(): void
    {
        $faultXml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body><soap:Fault>' .
            '<faultcode>soap:Server</faultcode>' .
            '<faultstring>Codigo[1101].Error en el formato del NIF</faultstring>' .
            '</soap:Fault></soap:Body></soap:Envelope>';

        $this->expectException(CensusValidationException::class);
        CensusValidationService::parseResponse($faultXml);
    }

    public function testParseResponseThrowsOnMalformedXml(): void
    {
        $this->expectException(CensusValidationException::class);
        CensusValidationService::parseResponse('<<not xml>>');
    }

    public function testParseResponseThrowsWhenNoContribuyente(): void
    {
        $emptyXml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body><vnif:VNifV2Sal xmlns:vnif="urn:test"/></soap:Body></soap:Envelope>';

        $this->expectException(CensusValidationException::class);
        CensusValidationService::parseResponse($emptyXml);
    }

    public function testParseResponseReadsAllResultVariants(): void
    {
        $xml = $this->getAllVariantsXml();

        $results = CensusValidationService::parseResponse($xml);

        $this->assertCount(6, $results);
        $this->assertSame(CensusValidationResult::RESULT_IDENTIFICADO, $results[0]->resultado);
        $this->assertSame(CensusValidationResult::RESULT_NO_IDENTIFICADO_SIMILAR, $results[1]->resultado);
        $this->assertSame(CensusValidationResult::RESULT_NO_IDENTIFICADO, $results[2]->resultado);
        $this->assertSame(CensusValidationResult::RESULT_IDENTIFICADO_BAJA, $results[3]->resultado);
        $this->assertSame(CensusValidationResult::RESULT_IDENTIFICADO_REVOCADO, $results[4]->resultado);
        $this->assertSame(CensusValidationResult::RESULT_NO_PROCESADO, $results[5]->resultado);
    }

    private function createMockClient(string $responseXml): \SoapClient
    {
        $mock = $this->createMock(\SoapClient::class);
        $mock->expects($this->once())
            ->method('__soapCall')
            ->with(CensusValidationService::SOAP_OPERATION, $this->anything());
        $mock->method('__getLastResponse')
            ->willReturn($responseXml);

        return $mock;
    }

    private function getTwoResultsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body>' .
            '<vnif:VNifV2Sal xmlns:vnif="' . CensusValidationService::OUTPUT_NS . '">' .
            '<vnif:Contribuyente>' .
            '<vnif:Nif>12345678Z</vnif:Nif>' .
            '<vnif:Nombre>Juan Perez</vnif:Nombre>' .
            '<vnif:Resultado>Identificado</vnif:Resultado>' .
            '</vnif:Contribuyente>' .
            '<vnif:Contribuyente>' .
            '<vnif:Nif>A12345678</vnif:Nif>' .
            '<vnif:Resultado>No identificado</vnif:Resultado>' .
            '</vnif:Contribuyente>' .
            '</vnif:VNifV2Sal>' .
            '</soap:Body></soap:Envelope>';
    }

    private function getAllVariantsXml(): string
    {
        $variants = [
            ['12345678Z', 'Juan Perez', CensusValidationResult::RESULT_IDENTIFICADO],
            ['12345679A', 'Maria Lopez', CensusValidationResult::RESULT_NO_IDENTIFICADO_SIMILAR],
            ['12345680B', 'Carlos Ruiz', CensusValidationResult::RESULT_NO_IDENTIFICADO],
            ['A12345678', 'ACME SL', CensusValidationResult::RESULT_IDENTIFICADO_BAJA],
            ['A12345679', 'BETA SL', CensusValidationResult::RESULT_IDENTIFICADO_REVOCADO],
            ['A12345680', 'GAMMA SL', CensusValidationResult::RESULT_NO_PROCESADO],
        ];

        $blocks = '';
        foreach ($variants as [$nif, $nombre, $resultado]) {
            $blocks .= '<vnif:Contribuyente>' .
                '<vnif:Nif>' . $nif . '</vnif:Nif>' .
                '<vnif:Nombre>' . $nombre . '</vnif:Nombre>' .
                '<vnif:Resultado>' . $resultado . '</vnif:Resultado>' .
                '</vnif:Contribuyente>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body>' .
            '<vnif:VNifV2Sal xmlns:vnif="' . CensusValidationService::OUTPUT_NS . '">' .
            $blocks .
            '</vnif:VNifV2Sal>' .
            '</soap:Body></soap:Envelope>';
    }
}