<?php

declare(strict_types=1);

namespace eseperio\verifactu\models;

/**
 * Model representing a request to the AEAT VNifV2 web service
 * (WS Masivo de Calidad de Datos Identificativos).
 *
 * Validates whether a NIF and a name/company name match the AEAT census.
 * Supports a single taxpayer or a batch of up to 20.000 taxpayers
 * (Contribuyente) per request, as allowed by the service.
 *
 * Input namespace: VNifV2Ent.xsd
 * @see https://www1.agenciatributaria.gob.es/wlpl/BURT-JDIT/ws/VNifV2SOAP
 */
class CensusValidationRequest extends Model
{
    /**
     * Maximum number of Contribuyente blocks allowed per request.
     */
    public const MAX_CONTRIBUYENTES = 20000;

    /**
     * Input XML namespace for the VNifV2Ent element.
     */
    public const NS = 'http://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/burt/jdit/ws/VNifV2Ent.xsd';

    /**
     * List of taxpayers to validate. Each entry is an associative array
     * with keys "nif" (required) and "nombre" (required for individuals,
     * optional for legal entities).
     *
     * @var array<int, array{nif: string, nombre: string|null}>
     */
    private array $contribuyentes = [];

    /**
     * The NIF of the first taxpayer, exposed for single-taxpayer convenience
     * and for validation through the base Model::validate() machinery.
     *
     * @var string|null
     */
    public $nif;

    /**
     * The name/company name of the first taxpayer.
     *
     * @var string|null
     */
    public $nombre;

    /**
     * Creates a request. When $nif is provided a first Contribuyente is
     * added automatically; use addContribuyente() to append more for a
     * batch consultation.
     *
     * @param string|null $nif    Tax ID (9 alphanumeric characters).
     * @param string|null $nombre Name or company name (required for individuals).
     */
    public function __construct(?string $nif = null, ?string $nombre = null)
    {
        if ($nif !== null) {
            $this->addContribuyente($nif, $nombre);
        }
    }

    /**
     * Appends a taxpayer to the request.
     *
     * @param string      $nif    Tax ID (required, 9 alphanumeric characters).
     * @param string|null $nombre Name or company name. Required for individuals,
     *                             optional for legal entities.
     * @return $this
     * @throws \InvalidArgumentException When the NIF is empty or the batch limit is exceeded.
     */
    public function addContribuyente(string $nif, ?string $nombre = null): static
    {
        $nif = trim($nif);
        if ($nif === '') {
            throw new \InvalidArgumentException('NIF is required for each Contribuyente.');
        }

        if (count($this->contribuyentes) >= self::MAX_CONTRIBUYENTES) {
            throw new \InvalidArgumentException(
                'Maximum number of Contribuyente per request (' . self::MAX_CONTRIBUYENTES . ') exceeded.'
            );
        }

        $this->contribuyentes[] = [
            'nif' => $nif,
            'nombre' => ($nombre === null) ? null : trim($nombre),
        ];

        // Keep the public convenience properties in sync with the first entry
        if (count($this->contribuyentes) === 1) {
            $this->nif = $nif;
            $this->nombre = $this->contribuyentes[0]['nombre'];
        }

        return $this;
    }

    /**
     * Returns the list of taxpayers in this request.
     *
     * @return array<int, array{nif: string, nombre: string|null}>
     */
    public function getContribuyentes(): array
    {
        return $this->contribuyentes;
    }

    /**
     * Returns the number of taxpayers in this request.
     */
    public function count(): int
    {
        return count($this->contribuyentes);
    }

    /**
     * Validation rules. The contribuyentes collection is required and must
     * not exceed the AEAT batch limit; each NIF must be a non-empty string.
     */
    public function rules(): array
    {
        return [
            [['contribuyentes'], 'required'],
            [['contribuyentes'], function ($value, $model): string|bool {
                if (!is_array($value) || count($value) === 0) {
                    return 'At least one Contribuyente is required.';
                }
                if (count($value) > self::MAX_CONTRIBUYENTES) {
                    return 'The number of Contribuyente exceeds the maximum of ' . self::MAX_CONTRIBUYENTES . '.';
                }
                foreach ($value as $index => $contribuyente) {
                    if (!is_array($contribuyente) || !isset($contribuyente['nif']) || trim((string) $contribuyente['nif']) === '') {
                        return 'Contribuyente at index ' . $index . ' is missing a NIF.';
                    }
                }

                return true;
            }],
        ];
    }

    /**
     * Builds the VNifV2Ent XML document (the SOAP Body payload, without the
     * SOAP envelope). One <vnif:Contribuyente> element is emitted per
     * registered taxpayer.
     *
     * @return \DOMDocument
     * @throws \InvalidArgumentException When the request is empty.
     */
    public function toXml(): \DOMDocument
    {
        if (empty($this->contribuyentes)) {
            throw new \InvalidArgumentException('Cannot build XML: no Contribuyente has been added.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::NS, 'vnif:VNifV2Ent');
        $doc->appendChild($root);

        foreach ($this->contribuyentes as $contribuyente) {
            $block = $doc->createElementNS(self::NS, 'vnif:Contribuyente');
            $block->appendChild($doc->createElementNS(self::NS, 'vnif:Nif', $contribuyente['nif']));
            if ($contribuyente['nombre'] !== null && $contribuyente['nombre'] !== '') {
                $block->appendChild($doc->createElementNS(self::NS, 'vnif:Nombre', $contribuyente['nombre']));
            }
            $root->appendChild($block);
        }

        return $doc;
    }
}