<?php

declare(strict_types=1);

namespace eseperio\verifactu\models;

/**
 * Model representing the result of a single taxpayer census validation
 * returned by the AEAT VNifV2 web service.
 *
 * Output namespace: VNifV2Sal.xsd
 */
class CensusValidationResult extends Model
{
    /** The NIF and name match the AEAT census. */
    public const RESULT_IDENTIFICADO = 'IDENTIFICADO';

    /** Only for individuals: does not match due to minor differences in name/surname. */
    public const RESULT_NO_IDENTIFICADO_SIMILAR = 'NO IDENTIFICADO-SIMILAR';

    /** Does not match the data provided. */
    public const RESULT_NO_IDENTIFICADO = 'NO IDENTIFICADO';

    /** Only for entities: identified but in "baja" (deregistered) state. */
    public const RESULT_IDENTIFICADO_BAJA = 'IDENTIFICADO-BAJA';

    /** Only for entities: identified but the NIF is revoked. */
    public const RESULT_IDENTIFICADO_REVOCADO = 'IDENTIFICADO-REVOCADO';

    /** The taxpayer was not processed (e.g. the request exceeded the batch limit). */
    public const RESULT_NO_PROCESADO = 'NO PROCESADO';

    /**
     * The NIF validated.
     * @var string|null
     */
    public $nif;

    /**
     * The name/company name validated.
     * @var string|null
     */
    public $nombre;

    /**
     * The result reported by AEAT. One of the RESULT_* constants.
     * @var string|null
     */
    public $resultado;

    /**
     * Returns true when the NIF is census-identified, including entities
     * that are in "baja" or have a revoked NIF (the taxpayer exists in the
     * census, albeit with caveats). Returns false for "No identificado*"
     * and "No procesado".
     */
    public function isIdentified(): bool
    {
        return $this->resultado !== null
            && str_starts_with(strtoupper($this->resultado), 'IDENTIFICADO');
    }

    /**
     * Returns true only when the NIF and name fully match the census
     * (RESULT_IDENTIFICADO). Stricter than isIdentified(): entities in
     * "baja" or with a revoked NIF are identified but not valid recipients.
     */
    public function isValid(): bool
    {
        return $this->resultado === self::RESULT_IDENTIFICADO;
    }

    /**
     * Returns true when the taxpayer was not processed by AEAT.
     */
    public function isNotProcessed(): bool
    {
        return $this->resultado === self::RESULT_NO_PROCESADO;
    }

    /**
     * Validation rules for the result model.
     */
    public function rules(): array
    {
        return [
            [['nif', 'resultado'], 'required'],
            [['nif', 'nombre', 'resultado'], 'string'],
            [['resultado'], function ($value, $model): string|bool {
                $valid = [
                    self::RESULT_IDENTIFICADO,
                    self::RESULT_NO_IDENTIFICADO,
                    self::RESULT_NO_IDENTIFICADO_SIMILAR,
                    self::RESULT_IDENTIFICADO_BAJA,
                    self::RESULT_IDENTIFICADO_REVOCADO,
                    self::RESULT_NO_PROCESADO,
                ];
                if (!in_array($value, $valid, true)) {
                    return 'Unknown VNifV2 result: ' . $value;
                }

                return true;
            }],
        ];
    }
}