<?php

declare(strict_types=1);

namespace eseperio\verifactu\services;

use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceRecord;

/**
 * Service responsible for generating the official SHA-256 hash ("huella") for invoice records,
 * following AEAT VERI*FACTU technical specification.
 *
 * The hash input string is raw values (no key=value) concatenated with '&', in the exact order
 * defined by AEAT spec. See verifactu-documentacion-completa-v2.md §10.
 */
class HashGeneratorService
{
    /**
     * Generates the SHA-256 hash for a given invoice record, according to AEAT specs.
     *
     * @return string Uppercase hexadecimal SHA-256 hash (64 characters)
     */
    public static function generate(InvoiceRecord $record): string
    {
        // Build the data string based on record type (submission or cancellation)
        $dataString = self::buildDataString($record);

        // Hash using SHA-256 and return as uppercase hexadecimal per AEAT spec
        return strtoupper(hash('sha256', $dataString));
    }

    /**
     * Builds the data string to be hashed, concatenating fields according to the AEAT rules.
     * For each type (InvoiceSubmission, InvoiceCancellation), uses the required field order.
     */
    protected static function buildDataString(InvoiceRecord $record): string
    {
        // Detect type: submission or cancellation
        if ($record instanceof InvoiceSubmission) {
            $invoiceId = $record->getInvoiceId();
            $chaining = $record->getChaining();
            // Raw values in AEAT-defined order:
            // IDEmisorFactura & NumSerieFactura & FechaExpedicionFactura(dd-mm-yyyy) & TipoFactura &
            // CuotaTotal & ImporteTotal & Huella_anterior & FechaHoraHusoGenRegistro
            $parts = [
                trim($invoiceId->issuerNif),
                trim($invoiceId->seriesNumber),
                InvoiceSerializer::formatDate((string) $invoiceId->issueDate),
                $record->invoiceType instanceof \BackedEnum ? $record->invoiceType->value : (string) $record->invoiceType,
                self::normalizeDecimal($record->taxAmount),
                self::normalizeDecimal($record->totalAmount),
                trim($chaining && $chaining->getPreviousInvoice() ? $chaining->getPreviousInvoice()->hash : ''),
                trim($record->recordTimestamp),
            ];
            return implode('&', $parts);
        }
        if ($record instanceof InvoiceCancellation) {
            $invoiceId = $record->getInvoiceId();
            $chaining = $record->getChaining();
            // Raw values in AEAT-defined order:
            // IDEmisorFactura & NumSerieFactura & FechaExpedicionFactura(dd-mm-yyyy) &
            // "Anulacion" & Huella_anterior & FechaHoraHusoGenRegistro
            $parts = [
                trim($invoiceId->issuerNif),
                trim($invoiceId->seriesNumber),
                InvoiceSerializer::formatDate((string) $invoiceId->issueDate),
                'Anulacion',
                trim($chaining && $chaining->getPreviousInvoice() ? $chaining->getPreviousInvoice()->hash : ''),
                trim($record->recordTimestamp),
            ];
            return implode('&', $parts);
        }
        else {
            throw new \InvalidArgumentException('Unsupported record type for hash generation.');
        }
    }

    /**
     * Normalizes a decimal value as required by AEAT (removes unnecessary trailing zeros, uses dot).
     *
     * @param mixed $value
     */
    protected static function normalizeDecimal($value): string
    {
        // Convert to float and format with exactly two decimals (dot separator) as used by AEAT examples
        return number_format((float) $value, 2, '.', '');
    }
}
