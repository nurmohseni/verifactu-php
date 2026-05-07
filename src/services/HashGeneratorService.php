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
 * The hash input string uses key=value pairs concatenated with '&', in the exact order
 * defined by AEAT. AEAT production diagnostic confirms key=value format despite documentation
 * examples showing raw values. See verifactu-documentacion-completa-v2.md §10.
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
        $dataString = self::buildDataString($record);
        return strtoupper(hash('sha256', $dataString));
    }

    /**
     * Builds the data string to be hashed, concatenating fields as key=value pairs.
     * Field names and order confirmed against AEAT production diagnostic (error 2000).
     */
    protected static function buildDataString(InvoiceRecord $record): string
    {
        if ($record instanceof InvoiceSubmission) {
            $invoiceId = $record->getInvoiceId();
            $chaining = $record->getChaining();

            $parts = [
                'IDEmisorFactura=' . trim($invoiceId->issuerNif),
                'NumSerieFactura=' . trim($invoiceId->seriesNumber),
                'FechaExpedicionFactura=' . InvoiceSerializer::formatDate((string) $invoiceId->issueDate),
                'TipoFactura=' . ($record->invoiceType instanceof \BackedEnum ? $record->invoiceType->value : (string) $record->invoiceType),
                'CuotaTotal=' . self::normalizeDecimal($record->taxAmount),
                'ImporteTotal=' . self::normalizeDecimal($record->totalAmount),
                'Huella=' . trim($chaining && $chaining->getPreviousInvoice() ? $chaining->getPreviousInvoice()->hash : ''),
                'FechaHoraHusoGenRegistro=' . trim($record->recordTimestamp),
            ];
            return implode('&', $parts);
        }

        if ($record instanceof InvoiceCancellation) {
            $invoiceId = $record->getInvoiceId();
            $chaining = $record->getChaining();

            // AEAT cancellation hash uses "Anulada" suffix on field names.
            // Confirmed by error 2000 diagnostic. No "Anulacion" literal is included.
            $parts = [
                'IDEmisorFacturaAnulada=' . trim($invoiceId->issuerNif),
                'NumSerieFacturaAnulada=' . trim($invoiceId->seriesNumber),
                'FechaExpedicionFacturaAnulada=' . InvoiceSerializer::formatDate((string) $invoiceId->issueDate),
                'Huella=' . trim($chaining && $chaining->getPreviousInvoice() ? $chaining->getPreviousInvoice()->hash : ''),
                'FechaHoraHusoGenRegistro=' . trim($record->recordTimestamp),
            ];
            return implode('&', $parts);
        }

        throw new \InvalidArgumentException('Unsupported record type for hash generation.');
    }

    /**
     * Normalizes a decimal value as required by AEAT (two decimals, dot separator).
     */
    protected static function normalizeDecimal($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
