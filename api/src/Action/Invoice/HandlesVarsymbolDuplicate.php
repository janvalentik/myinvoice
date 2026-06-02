<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

/**
 * Sdílené rozpoznání kolize čísla dokladu na unikátním indexu varsymbolu —
 * vydané faktury `uq_inv_supplier_varsymbol`, přijaté `uq_pi_supplier_varsymbol`
 * (obě `(supplier_id, varsymbol)`).
 *
 * Generátor se duplicitám aktivně vyhýbá, ruční čísla se předem kontrolují —
 * tohle je poslední pojistka: místo holé 500 (PDOException) vrátí akce
 * srozumitelnou hlášku. Viz issue #85 (řešení 4) a #103 (paralela pro přijaté).
 */
trait HandlesVarsymbolDuplicate
{
    /**
     * Vrátí uživatelskou hlášku, pokud výjimka je porušení unique indexu na varsymbolu;
     * jinak null (volající má výjimku přehodit dál).
     */
    private static function varsymbolDuplicateMessage(\PDOException $e, ?string $varsymbol): ?string
    {
        $isUnique = $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
        if (!$isUnique || !str_contains($e->getMessage(), 'varsymbol')) {
            return null;
        }

        $vs = trim((string) ($varsymbol ?? ''));
        return $vs !== ''
            ? "Číslo '{$vs}' už u tohoto dodavatele existuje. Zvol jiné, nebo nech pole prázdné — vygeneruje se automaticky."
            : 'Doklad s tímto číslem už u dodavatele existuje.';
    }
}
