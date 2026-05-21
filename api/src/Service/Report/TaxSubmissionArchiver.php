<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Sjednocený archiving + XSD validation pipeline pro EPO XML.
 *
 * Volaný z DphPriznani/KontrolniHlaseni/SouhrnneHlaseni/IncomeTax action `download()`
 * **před** posláním XML response. Vrátí ID archivovaného záznamu (pro odkaz v UI).
 */
final class TaxSubmissionArchiver
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly XmlSchemaValidator $validator,
    ) {}

    /**
     * Archivuje XML + spustí XSD validation (pokud schema existuje).
     *
     * @param array<string,mixed> $summary
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        ?int $generatedBy,
    ): array {
        // XSD validation
        $validation = $this->validator->validate($xml, $formCode);

        $id = $this->repo->archive(
            $supplierId,
            $formCode,
            $year,
            $month,
            $quarter,
            $xml,
            $summary,
            $validation['status'],
            $validation['errors'],
            $generatedBy,
        );

        return [
            'submission_id'     => $id,
            'validation_status' => $validation['status'],
            'validation_errors' => $validation['errors'],
        ];
    }
}
