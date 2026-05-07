<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\ComputerSystem;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\models\enums\YesNoType;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\services\HashGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the HashGeneratorService class.
 */
class HashGeneratorServiceTest extends TestCase
{
    /**
     * Test that the hash generator produces a consistent hash for the same input.
     */
    public function testHashConsistency(): void
    {
        $invoice = $this->createTestInvoice();
        
        // Generate the hash twice for the same invoice
        $hash1 = HashGeneratorService::generate($invoice);
        $hash2 = HashGeneratorService::generate($invoice);
        
        // Verify that both hashes are the same
        $this->assertEquals($hash1, $hash2, 'Hash should be consistent for the same input');
        
        // Verify that the hash is a valid SHA-256 hash (uppercase hexadecimal)
        $this->assertEquals(64, strlen($hash1), 'Hex-encoded SHA-256 hash should be 64 characters long');
        $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $hash1, 'Hash should be a valid uppercase hexadecimal string');
    }
    
    /**
     * Test that changing any input property changes the resulting hash.
     */
    public function testHashChangesWithInput(): void
    {
        $invoice = $this->createTestInvoice();
        
        // Generate the original hash
        $originalHash = HashGeneratorService::generate($invoice);
        
        // Change a property and verify the hash changes
        // Note: According to HashGeneratorService implementation, issuerName is not included in the hash
        // We need to modify a field that is actually used in hash calculation
        $invoice->totalAmount = 150.00;  // Changed from 121.00
        $changedHash1 = HashGeneratorService::generate($invoice);
        $this->assertNotEquals($originalHash, $changedHash1, 'Hash should change when totalAmount changes');
        
        // Reset and change another property with a different value
        $invoice = $this->createTestInvoice();
        $invoice->invoiceType = 'F2';  // Changed from STANDARD
        $changedHash2 = HashGeneratorService::generate($invoice);
        $this->assertNotEquals($originalHash, $changedHash2, 'Hash should change when invoiceType changes');
        
        // Verify the two changed hashes are different from each other
        $this->assertNotEquals($changedHash1, $changedHash2, 'Different changes should produce different hashes');
    }
    
    /**
     * Test that the hash algorithm follows the official AEAT Verifactu specification.
     * 
     * Verifies against the AEAT documentation example (§10):
     * Input:  B12345678 / F-2025-001 / 10-06-2025 / F1 / 21.00 / 121.00 / (empty) / 2025-06-10T12:30:00+02:00
     * String: "IDEmisorFactura=B12345678&NumSerieFactura=F-2025-001&FechaExpedicionFactura=10-06-2025&TipoFactura=F1&CuotaTotal=21.00&ImporteTotal=121.00&Huella=&FechaHoraHusoGenRegistro=2025-06-10T12:30:00+02:00"
     */
    public function testHashAlgorithmCompliance(): void
    {
        // AEAT production uses key=value format (confirmed by error 2000 diagnostic)
        $expectedString = 'IDEmisorFactura=B12345678&NumSerieFactura=F-2025-001&FechaExpedicionFactura=10-06-2025&TipoFactura=F1&CuotaTotal=21.00&ImporteTotal=121.00&Huella=&FechaHoraHusoGenRegistro=2025-06-10T12:30:00+02:00';
        $expectedHash = strtoupper(hash('sha256', $expectedString));

        // Create invoice matching the example
        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'F-2025-001';
        $invoiceId->issueDate = '2025-06-10'; // YYYY-MM-DD, should be converted to dd-mm-yyyy
        $invoice->setInvoiceId($invoiceId);
        $invoice->invoiceType = InvoiceType::STANDARD; // 'F1'
        $invoice->taxAmount = 21.00;
        $invoice->totalAmount = 121.00;
        $invoice->recordTimestamp = '2025-06-10T12:30:00+02:00';

        $chaining = new Chaining();
        $chaining->setAsFirstRecord();
        $invoice->setChaining($chaining);

        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = 'Sistema';
        $computerSystem->version = '1.0';
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;
        $invoice->setSystemInfo($computerSystem);

        $invoice->issuerName = 'Test';
        $invoice->hashType = HashType::SHA_256;
        $invoice->operationDescription = 'Test';
        $invoice->simplifiedInvoice = YesNoType::NO;
        $invoice->invoiceWithoutRecipient = YesNoType::NO;

        $hash = HashGeneratorService::generate($invoice);

        $this->assertEquals($expectedHash, $hash, 'Hash must match AEAT documentation example');
        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $hash);
    }

    /**
     * Test cancellation hash matches AEAT spec format.
     */
    public function testCancellationHashFormat(): void
    {
        $expectedString = 'IDEmisorFacturaAnulada=B12345678&NumSerieFacturaAnulada=F-2025-001&FechaExpedicionFacturaAnulada=10-06-2025&Huella=&FechaHoraHusoGenRegistro=2025-06-10T12:30:00+02:00';
        $expectedHash = strtoupper(hash('sha256', $expectedString));

        $cancellation = new InvoiceCancellation();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'F-2025-001';
        $invoiceId->issueDate = '2025-06-10';
        $cancellation->setInvoiceId($invoiceId);
        $cancellation->recordTimestamp = '2025-06-10T12:30:00+02:00';

        $chaining = new Chaining();
        $chaining->setAsFirstRecord();
        $cancellation->setChaining($chaining);

        $cancellation->issuerName = 'Test';
        $cancellation->hashType = HashType::SHA_256;
        $cancellation->generatedBy = 'E';
        $cancellation->previousRejection = YesNoType::NO;

        $hash = HashGeneratorService::generate($cancellation);

        $this->assertEquals($expectedHash, $hash, 'Cancellation hash must match AEAT spec format with literal "Anulacion"');
    }
    
    /**
     * Helper method to create a test invoice with consistent data for hash testing.
     * 
     * @return InvoiceSubmission
     */
    private function createTestInvoice(): InvoiceSubmission
    {
        $invoice = new InvoiceSubmission();
        
        // Set invoice ID with fixed values for consistency
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'TEST001';
        $invoiceId->issueDate = '2023-01-01';
        $invoice->setInvoiceId($invoiceId);
        
        // Set basic invoice data
        $invoice->issuerName = 'Test Company SL';
        $invoice->invoiceType = InvoiceType::STANDARD;
        $invoice->operationDescription = 'Test Invoice';
        $invoice->taxAmount = 21.00;
        $invoice->totalAmount = 121.00;
        
        // Add tax breakdown
        $invoice->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 100.00,
            'taxAmount' => 21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        
        // Set chaining data
        $chaining = new Chaining();
        $chaining->setAsFirstRecord();
        $invoice->setChaining($chaining);
        
        // Set system information
        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = 'Test System';
        $computerSystem->version = '1.0';
        $computerSystem->providerName = 'Test Provider';
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;
        
        // Set provider information
        $provider = new LegalPerson();
        $provider->name = 'Test Provider SL';
        $provider->nif = 'B87654321';
        $computerSystem->setProviderId($provider);
        
        $invoice->setSystemInfo($computerSystem);
        
        // Set other required fields
        $invoice->recordTimestamp = '2023-01-01T12:00:00+01:00';
        $invoice->hashType = HashType::SHA_256;
        
        // Optional fields
        $invoice->operationDate = '2023-01-01';
        $invoice->externalRef = 'TEST-001';
        $invoice->simplifiedInvoice = YesNoType::NO;
        $invoice->invoiceWithoutRecipient = YesNoType::NO;
        
        // Add recipients
        $recipient = new LegalPerson();
        $recipient->name = 'Test Client SL';
        $recipient->nif = '12345678Z';
        $invoice->addRecipient($recipient);
        
        return $invoice;
    }
}
