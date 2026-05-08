<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use eseperio\verifactu\models\BatchInvoiceResponse;
use eseperio\verifactu\models\Breakdown;
use eseperio\verifactu\models\BreakdownDetail;
use eseperio\verifactu\models\ComputerSystem;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\models\enums\YesNoType;
use eseperio\verifactu\services\VerifactuService;
use eseperio\verifactu\Verifactu;
use PHPUnit\Framework\TestCase;

class VerifactuTest extends TestCase
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

        $refConfig = new \ReflectionProperty(VerifactuService::class, 'config');
        $refConfig->setAccessible(true);
        $refConfig->setValue(null, []);

        $refClient = new \ReflectionProperty(VerifactuService::class, 'client');
        $refClient->setAccessible(true);
        $refClient->setValue(null, null);
    }

    private function createSuccessfulSoapMock(int $expectedCalls): \SoapClient
    {
        $mock = $this->createMock(\SoapClient::class);
        $mock->expects($this->exactly($expectedCalls))
            ->method('__soapCall')
            ->with('RegFactuSistemaFacturacion', $this->anything());

        $mock->method('__getLastResponse')
            ->willReturn($this->getSuccessfulResponseXml());

        return $mock;
    }

    private function getSuccessfulResponseXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
            '<soap:Body>' .
            '<RespuestaRegFactuSistemaFacturacion xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaSuministro.xsd">' .
            '<Cabecera>' .
            '<IDVersion>1.0</IDVersion>' .
            '<ObligadoEmision>' .
            '<NombreRazon>Test</NombreRazon>' .
            '<NIF>12345678Z</NIF>' .
            '</ObligadoEmision>' .
            '</Cabecera>' .
            '<TiempoEsperaEnvio>0</TiempoEsperaEnvio>' .
            '<EstadoEnvio>Correcto</EstadoEnvio>' .
            '<RespuestaLinea>' .
            '<EstadoRegistro>Correcto</EstadoRegistro>' .
            '<CodigoErrorRegistro/>' .
            '<DescripcionErrorRegistro/>' .
            '</RespuestaLinea>' .
            '</RespuestaRegFactuSistemaFacturacion>' .
            '</soap:Body>' .
            '</soap:Envelope>';
    }

    private function injectMockClient(\SoapClient $mock): void
    {
        $ref = new \ReflectionProperty(VerifactuService::class, 'client');
        $ref->setAccessible(true);
        $ref->setValue(null, $mock);
    }

    private function createBasicInvoiceSubmission(): InvoiceSubmission
    {
        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = '12345678Z';
        $invoiceId->seriesNumber = 'S001';
        $invoiceId->issueDate = '2023-01-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->issuerName = 'Test Company';
        $invoice->invoiceType = InvoiceType::STANDARD;
        $invoice->operationDescription = 'Test operation';
        $invoice->taxAmount = 21.00;
        $invoice->totalAmount = 121.00;
        $recipient = new LegalPerson();
        $recipient->name = 'Test Client';
        $recipient->nif = '87654321X';
        $invoice->addRecipient($recipient);
        $invoice->hashType = HashType::SHA_256;
        $invoice->hash = str_repeat('a', 64);
        $invoice->recordTimestamp = date('Y-m-d\TH:i:s');
        $invoice->setAsFirstRecord();

        $system = new ComputerSystem();
        $system->providerName = 'Test Provider';
        $system->systemName = 'Test System';
        $system->systemId = '01';
        $system->version = '1.0';
        $system->installationNumber = '001';
        $system->onlyVerifactu = YesNoType::YES;
        $system->multipleObligations = YesNoType::NO;
        $system->hasMultipleObligations = YesNoType::NO;
        $system->setProviderId(['name' => 'Test Provider', 'nif' => '12345678Z']);
        $invoice->setSystemInfo($system);

        $breakdown = new Breakdown();
        $detail = new BreakdownDetail();
        $detail->taxableBase = 100.00;
        $detail->taxRate = 21.00;
        $detail->taxAmount = 21.00;
        $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;
        $breakdown->addDetail($detail);
        $invoice->setBreakdown($breakdown);

        return $invoice;
    }

    private function createBasicInvoiceCancellation(): InvoiceCancellation
    {
        $cancellation = new InvoiceCancellation();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = '12345678Z';
        $invoiceId->seriesNumber = 'S001';
        $invoiceId->issueDate = '2023-01-01';
        $cancellation->setInvoiceId($invoiceId);
        $cancellation->hashType = HashType::SHA_256;
        $cancellation->hash = str_repeat('a', 64);
        $cancellation->recordTimestamp = date('Y-m-d\TH:i:s');
        $cancellation->setAsFirstRecord();
        $cancellation->previousRejection = YesNoType::NO;
        $cancellation->issuerName = 'Test Company';

        $system = new ComputerSystem();
        $system->providerName = 'Test Provider';
        $system->systemName = 'Test System';
        $system->systemId = '01';
        $system->version = '1.0';
        $system->installationNumber = '001';
        $system->onlyVerifactu = YesNoType::YES;
        $system->multipleObligations = YesNoType::NO;
        $system->hasMultipleObligations = YesNoType::NO;
        $system->setProviderId(['name' => 'Test Provider', 'nif' => '12345678Z']);
        $cancellation->setSystemInfo($system);

        return $cancellation;
    }

    public function testRegisterInvoicesFacade(): void
    {
        Verifactu::config(self::$dummyCertPath, '', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);
        $this->injectMockClient($this->createSuccessfulSoapMock(1));

        $result = Verifactu::registerInvoices([$this->createBasicInvoiceSubmission()]);

        $this->assertInstanceOf(BatchInvoiceResponse::class, $result);
        $this->assertTrue($result->isFullySuccessful());
        $this->assertSame(1, $result->totalRecordsSent);
    }

    public function testCancelInvoicesFacade(): void
    {
        Verifactu::config(self::$dummyCertPath, '', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);
        $this->injectMockClient($this->createSuccessfulSoapMock(1));

        $result = Verifactu::cancelInvoices([$this->createBasicInvoiceCancellation()]);

        $this->assertInstanceOf(BatchInvoiceResponse::class, $result);
        $this->assertTrue($result->isFullySuccessful());
        $this->assertSame(1, $result->totalRecordsSent);
    }
}
