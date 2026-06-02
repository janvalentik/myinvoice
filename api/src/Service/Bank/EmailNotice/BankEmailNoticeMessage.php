<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class BankEmailNoticeMessage
{
    /**
     * @param list<string> $authResults Hodnoty hlaviček Authentication-Results shora dolů
     *                                   (nejnovější hop = přijímací server = index 0).
     */
    public function __construct(
        public readonly ?int $uid,
        public readonly ?string $messageId,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $sender,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $raw,
        public readonly array $authResults = [],
    ) {}

    public function fallbackHash(): string
    {
        $date = $this->date?->format('c') ?? '';
        $base = strtolower($this->sender) . "\n" . $this->subject . "\n" . $date . "\n" . $this->text;
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($base)) ?? $base);
    }
}
