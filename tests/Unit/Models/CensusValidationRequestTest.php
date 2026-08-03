<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Models;

use eseperio\verifactu\models\CensusValidationRequest;
use PHPUnit\Framework\TestCase;

class CensusValidationRequestTest extends TestCase
{
    public function testConstructorAddsFirstContribuyente(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');

        $this->assertSame(1, $request->count());
        $this->assertSame('12345678Z', $request->nif);
        $this->assertSame('Juan Perez', $request->nombre);
        $contribuyentes = $request->getContribuyentes();
        $this->assertSame('12345678Z', $contribuyentes[0]['nif']);
        $this->assertSame('Juan Perez', $contribuyentes[0]['nombre']);
    }

    public function testConstructorWithoutArgsIsEmpty(): void
    {
        $request = new CensusValidationRequest();

        $this->assertSame(0, $request->count());
        $this->assertNull($request->nif);
        $this->assertNull($request->nombre);
    }

    public function testAddContribuyenteTrimsValues(): void
    {
        $request = new CensusValidationRequest();
        $request->addContribuyente('  12345678Z  ', '  Juan Perez  ');

        $contribuyentes = $request->getContribuyentes();
        $this->assertSame('12345678Z', $contribuyentes[0]['nif']);
        $this->assertSame('Juan Perez', $contribuyentes[0]['nombre']);
    }

    public function testAddContribuyenteWithNullNombre(): void
    {
        $request = new CensusValidationRequest('A12345678', null);

        $contribuyentes = $request->getContribuyentes();
        $this->assertNull($contribuyentes[0]['nombre']);
    }

    public function testAddContribuyenteEmptyNifThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CensusValidationRequest('   ');
    }

    public function testAddContribuyenteExceedingBatchLimitThrows(): void
    {
        $request = new CensusValidationRequest();
        for ($i = 0; $i < CensusValidationRequest::MAX_CONTRIBUYENTES; $i++) {
            $request->addContribuyente('N' . str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }

        $this->expectException(\InvalidArgumentException::class);
        $request->addContribuyente('OneTooMany');
    }

    public function testValidationPassesWithContribuyente(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');

        $this->assertEmpty($request->validate());
    }

    public function testValidationFailsWhenEmpty(): void
    {
        $request = new CensusValidationRequest();

        $errors = $request->validate();
        $this->assertNotEmpty($errors);
    }

    public function testToXmlBuildsValidDocument(): void
    {
        $request = new CensusValidationRequest();
        $request->addContribuyente('12345678Z', 'Juan Perez');
        $request->addContribuyente('A12345678', null);

        $doc = $request->toXml();
        $xml = $doc->saveXML();

        $this->assertStringContainsString('vnif:VNifV2Ent', $xml);
        $this->assertSame(2, substr_count($xml, '<vnif:Contribuyente>'));
        $this->assertStringContainsString('<vnif:Nif>12345678Z</vnif:Nif>', $xml);
        $this->assertStringContainsString('<vnif:Nombre>Juan Perez</vnif:Nombre>', $xml);
        $this->assertStringContainsString('<vnif:Nif>A12345678</vnif:Nif>', $xml);
        // A Contribuyente with a null Nombre must not emit a Nombre element.
        $this->assertStringNotContainsStringIgnoringCase('A12345678</vnif:Nombre>', $xml);
    }

    public function testToXmlOmitsNombreWhenEmpty(): void
    {
        $request = new CensusValidationRequest('12345678Z', '');

        $xml = $request->toXml()->saveXML();
        $this->assertStringContainsString('<vnif:Nif>12345678Z</vnif:Nif>', $xml);
        $this->assertStringNotContainsStringIgnoringCase('<vnif:Nombre', $xml);
    }

    public function testToXmlEmptyRequestThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CensusValidationRequest())->toXml();
    }

    public function testToXmlIsWellFormed(): void
    {
        $request = new CensusValidationRequest('12345678Z', 'Juan Perez');

        $xml = $request->toXml()->saveXML();
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($parsed, 'toXml() must produce well-formed XML.');
    }
}