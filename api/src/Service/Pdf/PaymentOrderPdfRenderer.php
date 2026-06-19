<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderer pro PDF platebního příkazu (portrait A4) — přehled plateb k odeslání do banky:
 * hlavička s účtem plátce + datem splatnosti, tabulka příjemců (účet, VS/KS/SS, částka,
 * ověření účtu) a součet. Sdílí fonty/branding přes MpdfFontConfig (Montserrat + JetBrains).
 */
final class PaymentOrderPdfRenderer
{
    private ?Environment $twig = null;

    /**
     * @param array<string,mixed> $data kanonický snapshot příkazu (PaymentOrderService::orderView)
     */
    public function render(array $data): string
    {
        $body = $this->twig()->render('payment-order.twig', $data);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',                       // na šířku — víc místa pro účet/symboly
            'orientation'   => 'L',
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Platební příkaz ' . (string) ($data['payment_date'] ?? ''));
        $mpdf->SetCreator('MyInvoice.cz');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/payment-order',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape'       => 'html',
                'strict_variables' => false,
                'cache'            => false,
            ]);
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', static function ($v) {
                return number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', static function ($v) {
                if (!$v) {
                    return '';
                }
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
        }
        return $this->twig;
    }
}
