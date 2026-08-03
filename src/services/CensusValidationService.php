<?php

declare(strict_types=1);

namespace eseperio\verifactu\services;

use eseperio\verifactu\exceptions\CensusValidationException;
use eseperio\verifactu\models\CensusValidationRequest;
use eseperio\verifactu\models\CensusValidationResult;

/**
 * Specialized service for the AEAT VNifV2 web service
 * (WS Masivo de Calidad de Datos Identificativos).
 *
 * Unlike the Verifactu invoice flow, VNifV2 is a plain SOAP request over
 * mutual TLS: the message carries no XAdES signature, only UTF-8 XML. This
 * service therefore reuses the certificate/TLS plumbing
 * (CertificateManagerService + SoapClientFactoryService) but intentionally
 * does NOT use XmlSignerService.
 *
 * Configuration (certificate path, password and VNifV2 endpoint) is shared
 * with the rest of the library through VerifactuService::config(), so a
 * single Verifactu::config() call is enough.
 */
class CensusValidationService
{
    /**
     * SOAP operation name used in non-WSDL mode. VNifV2 does not publish a
     * WSDL in this library; the operation name only affects the SOAPAction
     * header, which the AEAT endpoint does not require.
     */
    public const SOAP_OPERATION = 'VNifV2';

    /**
     * Input namespace (VNifV2Ent.xsd), used as the non-WSDL SOAP uri.
     */
    public const INPUT_NS = CensusValidationRequest::NS;

    /**
     * Output namespace (VNifV2Sal.xsd).
     */
    public const OUTPUT_NS = 'http://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/burt/jdit/ws/VNifV2Sal.xsd';

    /** @var \SoapClient|null */
    private static $client;

    /**
     * Validates one or more taxpayers against the AEAT census.
     *
     * @param CensusValidationRequest $request A request holding one or more
     *                                         Contribuyente blocks.
     * @return CensusValidationResult[] One result per requested taxpayer, in
     *                                  the same order as the request.
     * @throws \InvalidArgumentException When the request fails validation.
     * @throws CensusValidationException When AEAT returns a SOAP Fault or an
     *                                   unparseable response.
     * @throws \SoapFault When a low-level SOAP error occurs that is not an
     *                   AEAT fault.
     */
    public static function validate(CensusValidationRequest $request): array
    {
        $validation = $request->validate();
        if (!empty($validation)) {
            throw new \InvalidArgumentException(
                'CensusValidationRequest validation failed: ' . print_r($validation, true)
            );
        }

        // Build the VNifV2Ent payload (SOAP Body content, no envelope).
        $bodyXml = $request->toXml()->saveXML();
        // Strip the XML declaration: SoapVar(XSD_ANYXML) injects raw XML into
        // the SOAP Body and a duplicate declaration breaks the envelope.
        $bodyXml = preg_replace('/<\?xml.*?\?>/', '', $bodyXml);

        $client = self::getClient();

        try {
            $soapVar = new \SoapVar($bodyXml, XSD_ANYXML);
            $client->__soapCall(self::SOAP_OPERATION, [$soapVar]);
        } catch (\SoapFault $e) {
            throw self::translateFault($e);
        }

        $rawResponseXml = $client->__getLastResponse();

        return self::parseResponse($rawResponseXml);
    }

    /**
     * Returns a SOAP client configured for mutual TLS against the VNifV2
     * endpoint. Created once and cached for the lifetime of the process.
     *
     * @return \SoapClient
     * @throws \InvalidArgumentException When the library has not been configured.
     */
    protected static function getClient(): \SoapClient
    {
        if (self::$client === null) {
            $certPath = VerifactuService::getConfig(VerifactuService::CERT_PATH_KEY);
            $certPassword = VerifactuService::getConfig(VerifactuService::CERT_PASSWORD_KEY);
            $endpoint = VerifactuService::getConfig(VerifactuService::VNIF_ENDPOINT);

            // Build a PEM bundle compatible with SoapClient's local_cert option.
            $pemPath = CertificateManagerService::createSoapCompatiblePemTemp($certPath, $certPassword);

            self::$client = SoapClientFactoryService::createSoapClient(
                null, // non-WSDL mode: no local WSDL for VNifV2
                $pemPath,
                $certPassword,
                [
                    'location' => $endpoint,
                    'uri' => self::INPUT_NS,
                ]
            );
        }

        return self::$client;
    }

    /**
     * Replaces the cached SOAP client. Exposed for testing.
     */
    public static function setClient(?\SoapClient $client): void
    {
        self::$client = $client;
    }

    /**
     * Parses the raw VNifV2Sal SOAP response into CensusValidationResult[].
     *
     * @param string $xmlResponse
     * @return CensusValidationResult[]
     * @throws CensusValidationException When the response is a SOAP Fault or
     *                                   cannot be parsed.
     */
    public static function parseResponse(string $xmlResponse): array
    {
        $previousErrorSetting = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xmlResponse);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        if ($loaded === false) {
            throw new CensusValidationException(
                'VNifV2 response is not well-formed XML.',
                null,
                $xmlResponse
            );
        }

        $xpath = new \DOMXPath($doc);

        // A SOAP Fault means AEAT rejected the request before producing results.
        $faultNode = self::firstNode($xpath, '//*[local-name()="Fault"]');
        if ($faultNode !== null) {
            $faultString = self::findText($xpath, './/*[local-name()="faultstring"]');
            [$code, $message] = self::parseFaultString($faultString ?? '');
            throw new CensusValidationException(
                $message ?? 'AEAT VNifV2 returned a SOAP Fault.',
                $code,
                $faultString
            );
        }

        $results = [];
        foreach ($xpath->query('//*[local-name()="Contribuyente"]') as $contribuyenteNode) {
            $result = new CensusValidationResult();
            $result->nif = self::findText($xpath, './/*[local-name()="Nif"]', $contribuyenteNode);
            $result->nombre = self::findText($xpath, './/*[local-name()="Nombre"]', $contribuyenteNode);
            // AEAT returns Resultado in uppercase (e.g. "IDENTIFICADO").
            // Normalise to uppercase so callers can compare with === against
            // the RESULT_* constants regardless of any future casing drift.
            $resultado = self::findText($xpath, './/*[local-name()="Resultado"]', $contribuyenteNode);
            $result->resultado = $resultado !== null ? strtoupper($resultado) : null;
            $results[] = $result;
        }

        if (empty($results)) {
            throw new CensusValidationException(
                'VNifV2 response contained no Contribuyente results.',
                null,
                $xmlResponse
            );
        }

        return $results;
    }

    /**
     * Converts a SoapFault into a typed CensusValidationException, extracting
     * the embedded "Codigo[N]" token from the faultstring when present.
     */
    protected static function translateFault(\SoapFault $e): CensusValidationException
    {
        $faultString = $e->getMessage();
        [$code, $message] = self::parseFaultString($faultString);

        return new CensusValidationException(
            $message ?? ('Error calling AEAT VNifV2 service: ' . $faultString),
            $code,
            $faultString,
            $e
        );
    }

    /**
     * Extracts the AEAT error code and message from a faultstring formatted
     * as "Codigo[N].mensaje". Returns [code, message]; code is null when the
     * pattern is not found.
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    protected static function parseFaultString(string $faultString): array
    {
        if (preg_match('/Codigo\[(\d+)\]\.?(.*)/s', $faultString, $m)) {
            $code = (int) $m[1];
            $message = trim($m[2]);

            return [$code, $message !== '' ? $message : $faultString];
        }

        return [null, null];
    }

    /**
     * Returns the text content of the first node matching the XPath query,
     * or null when there is no match.
     */
    protected static function findText(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)->nodeValue);

        return $value !== '' ? $value : null;
    }

    /**
     * Returns the first DOMNode matched by the XPath query, or null.
     */
    protected static function firstNode(\DOMXPath $xpath, string $query): ?\DOMNode
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0);
    }
}