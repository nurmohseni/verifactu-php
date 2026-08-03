# Changelog

## [Unreleased]

### VNifV2 census validation (WS Masivo de Calidad de Datos Identificativos)

- **New `Verifactu::validateCensus()`** and **`Verifactu::validateCensusBatch()`** facade
  methods for the AEAT VNifV2 web service, which verifies whether a NIF and
  name/company name match the AEAT census. Unlike the invoice flow, VNifV2 uses
  plain SOAP over mutual TLS (no XAdES signature), reusing the same certificate
  configured via `Verifactu::config()`.
- New `CensusValidationRequest` model supporting a single taxpayer or a batch
  of up to 20.000 `Contribuyente` blocks per request.
- New `CensusValidationResult` model with the six documented AEAT outcomes
  (`Identificado`, `No identificado-similar`, `No identificado`,
  `Identificado-Baja`, `Identificado-Revocado`, `No procesado`) and
  `isValid()` / `isIdentified()` / `isNotProcessed()` helpers.
- New `CensusValidationService` (reuses `SoapClientFactoryService` and
  `CertificateManagerService`) and `CensusValidationException` exposing the
  AEAT error code parsed from the faultstring.
- `Verifactu::config()` now also resolves and stores the VNifV2 endpoint
  (selected by certificate type); `Verifactu::setVnifEndpoint()` allows
  overriding it (e.g. for a future sandbox URL).

### AEAT 2026 compatibility (v1.2.1, 23/02/2026)

- **[NL/PN] Netherlands country code**: The AEAT updated the internal denomination for
  Netherlands from 'Holanda' to 'Países Bajos' (AEAT v1.2.1, 23/02/2026). The ISO 3166-1
  alpha-2 code **'NL'** remains the correct value for `OtherID::$countryCode`. No migration
  needed; existing integrations using `'NL'` for Netherlands continue to work. See updated
  documentation in `OtherID::$countryCode`.

- **[QR] `huella` parameter**: The QR content builder (`QrGeneratorService::buildQrContent`)
  now omits the `huella` query parameter when the invoice hash has not yet been calculated
  (i.e., is null or empty). For VERI*FACTU invoices the hash must be set before QR generation.

- **[FechaFinVeriFactu]** Support for the optional `FechaFinVeriFactu` field (AEAT validation
  31.1.3) has been added:
  - New property `$fechaFinVeriFactu` on `InvoiceRecord` (available on both `InvoiceSubmission`
    and `InvoiceCancellation`). Accepted format: `DD-MM-YYYY` representing December 31st of
    the current or previous year (e.g. `'31-12-2025'`).
  - `InvoiceSerializer::wrapXmlWithRegFactuStructure()` accepts a new optional `$fechaFinVeriFactu`
    parameter and serialises `<sf:RemisionVoluntaria><sf:FechaFinVeriFactu>` when set.
  - `VerifactuService::registerInvoice()` and `cancelInvoice()` automatically pass the value
    from the record to the wrapper.

- Add setters for all complex properties. For collection properties, `add` method is provided. If properties are more
  than 3, it expects an object as parameter, otherwise it expects properties as parameters.
- Added models for all dependant schemas.
- Added support for choosing which engine to use for QrCode generation.
- Added support for changing the size of the QrCode.
- QrGeneratorService now can either return qr as string or save it to a file.
- No longer encode the QrCode as base64 by default, you should do it by yourself if you need it.
