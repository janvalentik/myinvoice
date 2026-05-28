<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

/**
 * User-facing chyba při práci s dokumenty — nese strojový kód + HTTP status,
 * aby ho Action mohl přeložit na Json::error bez ztráty kontextu.
 */
final class DocumentException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
