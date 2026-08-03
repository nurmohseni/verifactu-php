<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Models;

use eseperio\verifactu\models\CensusValidationResult;
use PHPUnit\Framework\TestCase;

class CensusValidationResultTest extends TestCase
{
    /**
     * @dataProvider identifiedResults
     */
    public function testIsIdentifiedTrueForIdentificadoVariants(string $resultado): void
    {
        $result = new CensusValidationResult();
        $result->resultado = $resultado;

        $this->assertTrue($result->isIdentified());
    }

    public static function identifiedResults(): array
    {
        return [
            'identificado' => [CensusValidationResult::RESULT_IDENTIFICADO],
            'identificado-baja' => [CensusValidationResult::RESULT_IDENTIFICADO_BAJA],
            'identificado-revocado' => [CensusValidationResult::RESULT_IDENTIFICADO_REVOCADO],
        ];
    }

    /**
     * @dataProvider notIdentifiedResults
     */
    public function testIsIdentifiedFalseForNonIdentificado(string $resultado): void
    {
        $result = new CensusValidationResult();
        $result->resultado = $resultado;

        $this->assertFalse($result->isIdentified());
    }

    public static function notIdentifiedResults(): array
    {
        return [
            'no-identificado' => [CensusValidationResult::RESULT_NO_IDENTIFICADO],
            'no-identificado-similar' => [CensusValidationResult::RESULT_NO_IDENTIFICADO_SIMILAR],
            'no-procesado' => [CensusValidationResult::RESULT_NO_PROCESADO],
        ];
    }

    public function testIsIdentifiedFalseWhenNull(): void
    {
        $result = new CensusValidationResult();
        $result->resultado = null;

        $this->assertFalse($result->isIdentified());
    }

    public function testIsValidOnlyForIdentificado(): void
    {
        $valid = new CensusValidationResult();
        $valid->resultado = CensusValidationResult::RESULT_IDENTIFICADO;
        $this->assertTrue($valid->isValid());

        $baja = new CensusValidationResult();
        $baja->resultado = CensusValidationResult::RESULT_IDENTIFICADO_BAJA;
        $this->assertFalse($baja->isValid());

        $revocado = new CensusValidationResult();
        $revocado->resultado = CensusValidationResult::RESULT_IDENTIFICADO_REVOCADO;
        $this->assertFalse($revocado->isValid());
    }

    public function testIsNotProcessed(): void
    {
        $notProcessed = new CensusValidationResult();
        $notProcessed->resultado = CensusValidationResult::RESULT_NO_PROCESADO;
        $this->assertTrue($notProcessed->isNotProcessed());

        $processed = new CensusValidationResult();
        $processed->resultado = CensusValidationResult::RESULT_IDENTIFICADO;
        $this->assertFalse($processed->isNotProcessed());
    }

    public function testValidationAcceptsKnownResults(): void
    {
        $result = new CensusValidationResult();
        $result->nif = '12345678Z';
        $result->resultado = CensusValidationResult::RESULT_IDENTIFICADO;

        $this->assertEmpty($result->validate());
    }

    public function testValidationRejectsUnknownResult(): void
    {
        $result = new CensusValidationResult();
        $result->nif = '12345678Z';
        $result->resultado = 'CompletamenteInventado';

        $errors = $result->validate();
        $this->assertNotEmpty($errors);
    }

    public function testValidationRequiresNifAndResultado(): void
    {
        $result = new CensusValidationResult();

        $errors = $result->validate();
        $this->assertNotEmpty($errors);
    }
}