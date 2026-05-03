<?php

declare(strict_types=1);

namespace MyInvoice;

use MyInvoice\Action\AresVies\AresLookupAction;
use MyInvoice\Action\AresVies\ViesLookupAction;
use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Client\ArchiveClientAction;
use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\DeleteClientAction;
use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Codebook\CodebookAction;
use MyInvoice\Action\Admin\EmailTemplateAction;
use MyInvoice\Action\Approval\PublicApprovalDecideAction;
use MyInvoice\Action\Approval\PublicApprovalGetAction;
use MyInvoice\Action\Approval\RequestApprovalAction;
use MyInvoice\Action\Approval\RequestApprovalTestAction;
use MyInvoice\Action\Approval\UpdateApprovalStatusAction;
use MyInvoice\Action\Admin\ExportAction;
use MyInvoice\Action\Admin\InvoicesZipAction;
use MyInvoice\Action\Admin\ListActivityLogAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\ExportCsvAction;
use MyInvoice\Action\Invoice\InvoiceActivityAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CloneInvoiceAction;
use MyInvoice\Action\Invoice\IssueFinalFromProformaAction;
use MyInvoice\Action\Invoice\PdfAction;
use MyInvoice\Action\Invoice\SendEmailAction;
use MyInvoice\Action\Invoice\SendReminderAction;
use MyInvoice\Action\Invoice\BulkSendRemindersAction;
use MyInvoice\Action\Invoice\SendTestEmailAction;
use MyInvoice\Action\Invoice\SendTestReminderAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\WorkReport\GetWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportAction;
use MyInvoice\Action\WorkReport\DeleteWorkReportAction;
use MyInvoice\Action\Project\ArchiveProjectAction;
use MyInvoice\Action\Project\CreateProjectAction;
use MyInvoice\Action\Project\DeleteProjectAction;
use MyInvoice\Action\Project\GetProjectAction;
use MyInvoice\Action\Project\ListProjectsAction;
use MyInvoice\Action\Project\ProjectStatsAction;
use MyInvoice\Action\Project\UpdateProjectAction;
use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\LogoutAction;
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Action\Auth\SetupAresLookupAction;
use MyInvoice\Action\Auth\SetupSampleAction;
use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Action\System\HealthAction;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health', HealthAction::class);

        $app->group('/api/auth', function ($g) {
            $g->get ('/setup-status',    SetupStatusAction::class);
            $g->post('/setup',           SetupAction::class);
            $g->post('/setup-ares-lookup', SetupAresLookupAction::class);  // public ARES proxy během setup wizardu
            $g->post('/setup-sample',    SetupSampleAction::class);         // public sample data generator (jen pokud nejsou data)
            $g->post('/login',           LoginAction::class);
            $g->post('/logout',          LogoutAction::class);
            $g->get ('/me',              MeAction::class);
            $g->post('/change-password', ChangePasswordAction::class);
            $g->post('/forgot',          ForgotPasswordAction::class);
            $g->post('/reset',           ResetPasswordAction::class);
            // TOTP (2FA)
            $g->get ('/totp/status',     [TotpAction::class, 'status']);
            $g->post('/totp/setup',      [TotpAction::class, 'setup']);
            $g->post('/totp/enable',     [TotpAction::class, 'enable']);
        });

        // ARES + VIES lookups (vyžadují auth)
        $app->post('/api/clients/lookup-ares', AresLookupAction::class);
        $app->post('/api/clients/lookup-vies', ViesLookupAction::class);

        // Codebooks
        $app->get('/api/codebooks/countries',  [CodebookAction::class, 'countries']);
        $app->get('/api/codebooks/currencies', [CodebookAction::class, 'currencies']);
        $app->get('/api/codebooks/vat-rates',  [CodebookAction::class, 'vatRates']);

        // Clients
        $app->get   ('/api/clients',                 ListClientsAction::class);
        $app->post  ('/api/clients',                 CreateClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}',     GetClientAction::class);
        $app->put   ('/api/clients/{id:[0-9]+}',     UpdateClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/archive',   ArchiveClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/unarchive', ArchiveClientAction::class);
        $app->delete('/api/clients/{id:[0-9]+}',           DeleteClientAction::class);

        // Projects
        $app->get   ('/api/clients/{client_id:[0-9]+}/projects', ListProjectsAction::class);
        $app->get   ('/api/projects/stats',          ProjectStatsAction::class);
        $app->get   ('/api/projects',                ListProjectsAction::class);
        $app->post  ('/api/projects',                CreateProjectAction::class);
        $app->get   ('/api/projects/{id:[0-9]+}',    GetProjectAction::class);
        $app->put   ('/api/projects/{id:[0-9]+}',    UpdateProjectAction::class);
        $app->post  ('/api/projects/{id:[0-9]+}/archive', ArchiveProjectAction::class);
        $app->delete('/api/projects/{id:[0-9]+}',         DeleteProjectAction::class);

        // Invoices (M3 — draft + editor + sumace; vystavení/odeslání/PDF přijde v M4)
        $app->get    ('/api/invoices',              ListInvoicesAction::class);
        $app->get    ('/api/invoices/export.csv',   ExportCsvAction::class);
        $app->post   ('/api/invoices',              CreateInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}',  GetInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/activity', InvoiceActivityAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}',  UpdateInvoiceAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}',  DeleteInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue',     IssueInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/mark-paid', MarkPaidAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/cancel',    CancelInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdf',       PdfAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/send',      SendEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/send-test', SendTestEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder',  SendReminderAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder-test', SendTestReminderAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue-final', IssueFinalFromProformaAction::class);
        $app->post   ('/api/invoices/bulk-reissue',          BulkReissueAction::class);
        $app->post   ('/api/invoices/bulk-reminder',         BulkSendRemindersAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/clone',     CloneInvoiceAction::class);

        // Work reports — výkaz víceprací (M5)
        $app->get    ('/api/invoices/{id:[0-9]+}/work-report', GetWorkReportAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/work-report', SaveWorkReportAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/work-report', DeleteWorkReportAction::class);

        // Schvalování výkazu zákazníkem (M8)
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval',      RequestApprovalAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval-test', RequestApprovalTestAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/approval-status',       UpdateApprovalStatusAction::class);

        // Public schvalovací endpointy (bez auth, jen token)
        $app->get    ('/api/public/approval/{token:[a-f0-9]{32,128}}',          PublicApprovalGetAction::class);
        $app->post   ('/api/public/approval/{token:[a-f0-9]{32,128}}/decide',   PublicApprovalDecideAction::class);

        // Dashboard
        $app->get ('/api/dashboard/summary',        SummaryAction::class);

        // Admin (M6)
        $app->get    ('/api/admin/activity-log',    ListActivityLogAction::class);
        $app->get    ('/api/admin/invoices-zip',    InvoicesZipAction::class);  // legacy — drží se kvůli historickým bookmark URL
        $app->get    ('/api/admin/export',          ExportAction::class);       // generic export (?format=pdf-zip|isdoc|pohoda&month=YYYY-MM)
        $app->get    ('/api/admin/users',           [UserAdminAction::class, 'list']);
        $app->post   ('/api/admin/users',           [UserAdminAction::class, 'create']);
        $app->put    ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'update']);
        $app->delete ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'delete']);

        // Email šablony (admin only)
        $app->get    ('/api/admin/email-templates',                                  [EmailTemplateAction::class, 'list']);
        $app->get    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'get']);
        $app->put    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'put']);
        $app->delete ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'delete']);

        // Multi-supplier (M7)
        $app->get    ('/api/suppliers',                     [SettingsAction::class, 'listSuppliers']);
        $app->post   ('/api/suppliers',                     [SettingsAction::class, 'createSupplier']);
        $app->get    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'getSupplierById']);
        $app->put    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'updateSupplierById']);
        $app->delete ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'deleteSupplierById']);

        // Settings (M6) — aktuální supplier (z X-Supplier-Id)
        $app->get ('/api/settings/supplier',                [SettingsAction::class, 'getSupplier']);
        $app->put ('/api/settings/supplier',                [SettingsAction::class, 'updateSupplier']);
        $app->get    ('/api/settings/currencies',                     [SettingsAction::class, 'listCurrencies']);
        $app->post   ('/api/settings/currencies',                     [SettingsAction::class, 'createCurrency']);
        $app->put    ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'updateCurrency']);
        $app->delete ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'deleteCurrency']);

        $app->get    ('/api/settings/vat-rates',                      [SettingsAction::class, 'listVatRates']);
        $app->post   ('/api/settings/vat-rates',                      [SettingsAction::class, 'createVatRate']);
        $app->put    ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'updateVatRate']);
        $app->delete ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'deleteVatRate']);

        $app->get    ('/api/settings/countries',                      [SettingsAction::class, 'listCountries']);
        $app->post   ('/api/settings/countries',                      [SettingsAction::class, 'createCountry']);
        $app->put    ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'updateCountry']);
        $app->delete ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'deleteCountry']);

        // Bank statements (M5b)
        $app->post ('/api/bank-statements/upload',           [BankStatementAction::class, 'upload']);
        $app->post ('/api/bank-statements/scan',             [BankStatementAction::class, 'scan']);
        $app->get  ('/api/bank-statements',                  [BankStatementAction::class, 'list']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}',      [BankStatementAction::class, 'detail']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/match',  [BankStatementAction::class, 'manualMatch']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/ignore', [BankStatementAction::class, 'ignore']);

        // 404 fallback pro /api/*
        $app->any('/api/{path:.*}', function ($req, $res) {
            return \MyInvoice\Http\Json::error($res, 'not_found', 'Route not found', 404);
        });
    }
}
