<?php

declare(strict_types=1);

namespace eseperio\verifactu\exceptions;

/**
 * Typed exception raised when the AEAT VNifV2 web service returns a SOAP
 * Fault or an otherwise unusable response.
 *
 * AEAT embeds the error code inside the faultstring as "Codigo[N].mensaje"
 * rather than as a structured field; this exception exposes the parsed
 * numeric code and the original fault message.
 */
class CensusValidationException extends \RuntimeException
{
    /**
     * The AEAT error code extracted from the faultstring, or null when the
     * response was a SOAP Fault without an embedded "Codigo[N]" token.
     *
     * @var int|string|null
     */
    private $aeatCode;

    /**
     * The raw faultstring reported by AEAT.
     *
     * @var string|null
     */
    private $faultString;

    /**
     * @param string          $message     Human-readable message.
     * @param int|string|null $aeatCode    AEAT error code parsed from the faultstring.
     * @param string|null     $faultString Original faultstring from AEAT.
     * @param \Throwable|null $previous    Original SoapFault, if any.
     */
    public function __construct(string $message, $aeatCode = null, ?string $faultString = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, is_int($aeatCode) ? $aeatCode : 0, $previous);
        $this->aeatCode = $aeatCode;
        $this->faultString = $faultString;
    }

    /**
     * Returns the AEAT error code parsed from the faultstring, or null.
     *
     * @return int|string|null
     */
    public function getAeatCode()
    {
        return $this->aeatCode;
    }

    /**
     * Returns the raw faultstring reported by AEAT.
     */
    public function getFaultString(): ?string
    {
        return $this->faultString;
    }
}