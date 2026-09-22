<?php

declare(strict_types=1);

namespace eseperio\verifactu\models\enums;

/**
 * Enumeration for the previous-rejection indicator (RechazoPrevioType).
 *
 * Distinct from {@see YesNoType} because the AEAT schema defines a THIRD
 * value the yes/no pair cannot express:
 *
 * - `N` — no previous rejection.
 * - `S` — there was a previous rejection. Only valid together with
 *   `Subsanacion=S` (AEAT errors 1161 / 1275), and its documented use is
 *   the ANULACIÓN after a rejection.
 * - `X` — regardless of any previous rejection, the record does not exist
 *   in AEAT. This is the value for an ALTA POR RECHAZO: the record was
 *   rejected, so it never entered AEAT, and the amended record is submitted
 *   as a fresh alta with `Subsanacion=S` (AEAT error 1153).
 *
 * @see SuministroInformacion.xsd — RechazoPrevioType
 */
enum PreviousRejectionType: string
{
    /**
     * No previous rejection by AEAT.
     */
    case NO = 'N';

    /**
     * There was a previous rejection by AEAT.
     */
    case YES = 'S';

    /**
     * The record does not exist in AEAT (it was never successfully sent).
     */
    case NOT_IN_AEAT = 'X';
}
