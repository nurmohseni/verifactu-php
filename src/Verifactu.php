<?php

declare(strict_types=1);
// Main entry point of the Verifactu library

namespace eseperio\verifactu;

use eseperio\verifactu\models\BatchInvoiceResponse;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\InvoiceRecord;
use eseperio\verifactu\models\InvoiceResponse;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\QueryResponse;
use eseperio\verifactu\models\CensusValidationRequest;
use eseperio\verifactu\models\CensusValidationResult;
use eseperio\verifactu\services\CensusValidationService;
use eseperio\verifactu\services\VerifactuService;

class Verifactu
{
    public const ENVIRONMENT_PRODUCTION = 'production';
    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /**
     * Production environment URL.
     */
    public const URL_PRODUCTION = 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Production environment URL (seal certificate).
     */
    public const URL_PRODUCTION_SEAL = 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Test (homologation) environment URL.
     */
    public const URL_TEST = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Test (seal certificate) environment URL.
     */
    public const URL_TEST_SEAL = 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * QR verification URL (production).
     */
    public const QR_VERIFICATION_URL_PRODUCTION = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';

    /**
     * QR verification URL (testing/homologation).
     */
    public const QR_VERIFICATION_URL_TEST = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    /**
     * VNifV2 (census validation) production endpoint (persona física / representante).
     */
    public const URL_VNIF_PRODUCTION = 'https://www1.agenciatributaria.gob.es/wlpl/BURT-JDIT/ws/VNifV2SOAP';

    /**
     * VNifV2 (census validation) production endpoint (seal certificate).
     */
    public const URL_VNIF_PRODUCTION_SEAL = 'https://www10.agenciatributaria.gob.es/wlpl/BURT-JDIT/ws/VNifV2SOAP';

    public const TYPE_CERTIFICATE = 'certificate';
    public const TYPE_SEAL = 'seal';

    /**
     * @param $certPath string Path to the certificate file.
     * @param $certPassword string Password for the certificate.
     * @param $certType string Type of certificate, either 'certificate' or 'seal'.
     * @param $environment string Environment to use, either 'production' or 'sandbox'.
     */
    public static function config($certPath, $certPassword, $certType, $environment = self::ENVIRONMENT_PRODUCTION): void
    {
        $endpoint = match ($environment) {
            self::ENVIRONMENT_PRODUCTION => $certType === self::TYPE_SEAL ? self::URL_PRODUCTION_SEAL : self::URL_PRODUCTION,
            self::ENVIRONMENT_SANDBOX => $certType === self::TYPE_SEAL ? self::URL_TEST_SEAL : self::URL_TEST,
            default => throw new \InvalidArgumentException("Invalid environment: $environment")
        };

        $qrValidationUrl = match ($environment) {
            self::ENVIRONMENT_PRODUCTION => self::QR_VERIFICATION_URL_PRODUCTION,
            self::ENVIRONMENT_SANDBOX => self::QR_VERIFICATION_URL_TEST,
            default => throw new \InvalidArgumentException("Invalid environment: $environment")
        };

        // VNifV2 (census validation) endpoint. AEAT only documents a
        // production URL for this service (no sandbox), so both environments
        // resolve to the production endpoint, selected by certificate type.
        $vnifEndpoint = $certType === self::TYPE_SEAL ? self::URL_VNIF_PRODUCTION_SEAL : self::URL_VNIF_PRODUCTION;

        VerifactuService::config([
            VerifactuService::CERT_PATH_KEY => $certPath,
            VerifactuService::CERT_PASSWORD_KEY => $certPassword,
            VerifactuService::SOAP_ENDPOINT => $endpoint,
            VerifactuService::QR_VERIFICATION_URL => $qrValidationUrl,
            VerifactuService::VNIF_ENDPOINT => $vnifEndpoint,
        ]);
    }

    /**
     * Overrides the VNifV2 (census validation) endpoint.
     *
     * AEAT does not currently publish a sandbox URL for VNifV2; this setter
     * keeps the endpoint configurable in case AEAT publishes one in the
     * future. Must be called after Verifactu::config().
     *
     * @param string $url Full VNifV2 SOAP endpoint URL.
     */
    public static function setVnifEndpoint(string $url): void
    {
        VerifactuService::config(array_merge(
            VerifactuService::getConfigAll(),
            [VerifactuService::VNIF_ENDPOINT => $url]
        ));
    }

    /**
     * Registers a new invoice (Alta) with AEAT via VERI*FACTU.
     *
     * @throws \DOMException
     * @throws \SoapFault
     */
    public static function registerInvoice(InvoiceSubmission $invoice): InvoiceResponse
    {
        return VerifactuService::registerInvoice($invoice);
    }

    /**
     * Cancels an invoice (Anulación) with AEAT via VERI*FACTU.
     */
    public static function cancelInvoice(InvoiceCancellation $cancellation): InvoiceResponse
    {
        return VerifactuService::cancelInvoice($cancellation);
    }

    /**
     * Registers multiple invoices in a batch with AEAT via VERI*FACTU.
     *
     * @param InvoiceSubmission[] $invoices
     * @param int|null $maxBatchSize Maximum records per batch (defaults to 1000)
     * @return BatchInvoiceResponse
     * @throws \InvalidArgumentException
     * @throws \SoapFault
     */
    public static function registerInvoices(array $invoices, ?int $maxBatchSize = null): BatchInvoiceResponse
    {
        return VerifactuService::registerInvoices($invoices, $maxBatchSize);
    }

    /**
     * Cancels multiple invoices in a batch with AEAT via VERI*FACTU.
     *
     * @param InvoiceCancellation[] $cancellations
     * @param int|null $maxBatchSize Maximum records per batch (defaults to 1000)
     * @return BatchInvoiceResponse
     * @throws \InvalidArgumentException
     * @throws \SoapFault
     */
    public static function cancelInvoices(array $cancellations, ?int $maxBatchSize = null): BatchInvoiceResponse
    {
        return VerifactuService::cancelInvoices($cancellations, $maxBatchSize);
    }

    /**
     * Queries submitted invoices from AEAT via VERI*FACTU.
     */
    public static function queryInvoices(InvoiceQuery $query): QueryResponse
    {
        return VerifactuService::queryInvoices($query);
    }

    /**
     * Generates a base64 QR code for the provided invoice.
     *
     * @return string base64-encoded PNG QR code
     */
    public static function generateInvoiceQr(InvoiceRecord $record): string
    {
        return VerifactuService::generateInvoiceQr($record);
    }

    /**
     * Validates a single taxpayer against the AEAT census using the VNifV2
     * web service (Calidad de Datos Identificativos).
     *
     * Reuses the certificate configured via Verifactu::config(); no XAdES
     * signature is involved, only mutual TLS.
     *
     * @param string      $nif    Tax ID to validate (9 alphanumeric characters).
     * @param string|null $nombre Name or company name. Required for individuals,
     *                             optional for legal entities.
     * @return CensusValidationResult
     * @throws \InvalidArgumentException When the input fails validation.
     * @throws \eseperio\verifactu\exceptions\CensusValidationException When AEAT returns a SOAP Fault.
     * @throws \SoapFault On low-level SOAP errors.
     */
    public static function validateCensus(string $nif, ?string $nombre = null): CensusValidationResult
    {
        $request = new CensusValidationRequest($nif, $nombre);

        return CensusValidationService::validate($request)[0];
    }

    /**
     * Validates multiple taxpayers in a single VNifV2 batch request (up to
     * 20.000 Contribuyente per request).
     *
     * @param array<int, array{nif: string, nombre?: string|null}|CensusValidationRequest> $contribuyentes
     *        Each entry is either a CensusValidationRequest or an array with
     *        keys "nif" (required) and "nombre" (optional).
     * @return CensusValidationResult[] One result per input taxpayer, in order.
     * @throws \InvalidArgumentException When the input fails validation.
     * @throws \eseperio\verifactu\exceptions\CensusValidationException When AEAT returns a SOAP Fault.
     * @throws \SoapFault On low-level SOAP errors.
     */
    public static function validateCensusBatch(array $contribuyentes): array
    {
        $request = new CensusValidationRequest();
        foreach ($contribuyentes as $entry) {
            if ($entry instanceof CensusValidationRequest) {
                foreach ($entry->getContribuyentes() as $c) {
                    $request->addContribuyente($c['nif'], $c['nombre']);
                }
            } elseif (is_array($entry) && isset($entry['nif'])) {
                $request->addContribuyente($entry['nif'], $entry['nombre'] ?? null);
            } else {
                throw new \InvalidArgumentException(
                    'Each contribuyente must be a CensusValidationRequest or an array with a "nif" key.'
                );
            }
        }

        return CensusValidationService::validate($request);
    }
}
