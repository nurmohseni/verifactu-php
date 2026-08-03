<?php

declare(strict_types=1);

namespace Verifactu;

use eseperio\verifactu\exceptions\CensusValidationException;
use eseperio\verifactu\models\CensusValidationResult;
use eseperio\verifactu\utils\EnvLoader;
use eseperio\verifactu\Verifactu;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the AEAT VNifV2 web service
 * (WS Masivo de Calidad de Datos Identificativos).
 *
 * These tests call the real AEAT production endpoint and require a valid
 * certificate. They are skipped when no certificate is configured.
 *
 * Required environment variables (in your .env file):
 * - VERIFACTU_CERT_PATH: Path to your certificate file
 * - VERIFACTU_CERT_PASSWORD: Password for the certificate
 * - VERIFACTU_CERT_TYPE: Certificate type (certificate or seal)
 * - VERIFACTU_ENVIRONMENT: Environment (sandbox or production)
 *
 * Optional:
 * - TEST_ISSUER_NIF: A NIF known to exist in the AEAT census (defaults to a
 *                   well-known public NIF used in AEAT documentation examples).
 * - TEST_ISSUER_NAME: The matching name for TEST_ISSUER_NIF.
 */
class CensusValidationSandboxTest extends TestCase
{
    private string $knownNif;
    private string $knownName;

    protected function setUp(): void
    {
        parent::setUp();

        if (!EnvLoader::hasSandboxConfig()) {
            $this->markTestSkipped(
                'VNifV2 integration tests skipped. Set VERIFACTU_CERT_PATH, ' .
                'VERIFACTU_CERT_PASSWORD, VERIFACTU_CERT_TYPE and VERIFACTU_ENVIRONMENT ' .
                'in your .env file to run them.'
            );
        }

        Verifactu::config(
            EnvLoader::getCertPath(),
            EnvLoader::getCertPassword(),
            EnvLoader::getCertType(),
            EnvLoader::getEnvironment()
        );

        // A NIF/name pair expected to be present in the AEAT census. The
        // AEAT itself uses this NIF in its VNifV2 documentation examples, so
        // it is a stable choice for a smoke test against the real service.
        $this->knownNif = EnvLoader::get('TEST_ISSUER_NIF', '12345678Z');
        $this->knownName = EnvLoader::get('TEST_ISSUER_NAME', 'Juan Perez');
    }

    /**
     * Validates a single taxpayer and asserts a usable result is returned.
     */
    public function testValidateCensusSingle(): void
    {
        $result = Verifactu::validateCensus($this->knownNif, $this->knownName);

        $this->assertInstanceOf(CensusValidationResult::class, $result);
        $this->assertSame(strtoupper($this->knownNif), strtoupper($result->nif));
        // The result must be one of the documented outcomes.
        $this->assertNotNull($result->resultado);
        $this->assertContains($result->resultado, [
            CensusValidationResult::RESULT_IDENTIFICADO,
            CensusValidationResult::RESULT_NO_IDENTIFICADO,
            CensusValidationResult::RESULT_NO_IDENTIFICADO_SIMILAR,
            CensusValidationResult::RESULT_IDENTIFICADO_BAJA,
            CensusValidationResult::RESULT_IDENTIFICADO_REVOCADO,
            CensusValidationResult::RESULT_NO_PROCESADO,
        ]);
    }

    /**
     * Validates a batch of taxpayers and asserts one result per input.
     */
    public function testValidateCensusBatch(): void
    {
        $results = Verifactu::validateCensusBatch([
            ['nif' => $this->knownNif, 'nombre' => $this->knownName],
            ['nif' => 'A12345678'], // legal entity, name optional
        ]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(CensusValidationResult::class, $result);
            $this->assertNotNull($result->resultado);
        }
    }

    /**
     * A malformed NIF should be rejected by AEAT; depending on the cert, the
     * service returns either a SOAP Fault (wrapped in CensusValidationException)
     * or a "No procesado" result. Either outcome is acceptable here.
     */
    public function testValidateCensusMalformedNifHandledGracefully(): void
    {
        try {
            $result = Verifactu::validateCensus('NOTANIF', 'Nobody');
            $this->assertContains($result->resultado, [
                CensusValidationResult::RESULT_NO_PROCESADO,
                CensusValidationResult::RESULT_NO_IDENTIFICADO,
            ]);
        } catch (CensusValidationException $e) {
            // A SOAP Fault with an AEAT code is the expected rejection path.
            $this->assertNotNull($e->getFaultString());
            $this->addToAssertionCount(1);
        }
    }
}