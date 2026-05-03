<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ProjectRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.supplier_id AS supplier_id,
                    cur.code AS currency
               FROM projects p
               JOIN clients   c   ON c.id   = p.client_id
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->cast($row);
        $row['billing_emails'] = $this->billingEmailsFor($id);
        return $row;
    }

    public function listForClient(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.status, p.currency_id, cur.code AS currency,
                    p.hourly_rate, p.payment_due_days, p.project_number,
                    p.contract_number, p.budget_total, p.budget_yearly, p.budget_monthly, p.archived_at
               FROM projects p
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.client_id = ?
              ORDER BY p.archived_at IS NOT NULL, p.status = "active" DESC, p.name'
        );
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->cast($r), $rows);
    }

    public function listAll(array $filters = [], int $page = 1, int $perPage = 50, string $sort = 'name'): array
    {
        $where = ['p.archived_at IS NULL'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'c.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'p.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Whitelist řazení (defense proti SQLi přes user input)
        $orderBy = match ($sort) {
            'revenue'       => 'revenue DESC, p.name',
            'last_activity' => 'last_invoice_date IS NULL, last_invoice_date DESC, p.name',
            default         => "p.status = 'active' DESC, p.name",
        };

        $offset = max(0, ($page - 1) * $perPage);
        // Cache `project_revenue_cache` per měnu projektu (přepočítáváno přes StatsRecomputer)
        $sql = "SELECT p.*, c.company_name AS client_company_name,
                       cur.code AS currency,
                       COALESCE(prc.revenue, 0) AS revenue,
                       prc.last_invoice_date,
                       COALESCE(prc.invoice_count, 0) AS invoice_count
                  FROM projects p
                  JOIN clients   c   ON c.id   = p.client_id
                  JOIN currencies cur ON cur.id = p.currency_id
             LEFT JOIN project_revenue_cache prc ON prc.project_id = p.id AND prc.currency_id = p.currency_id
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map(fn (array $r) => $this->cast($r), $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create(array $data): int
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $data['client_id'];
        // Currency lookup scope: supplier_id z klienta projektu
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $supplierId = (int) $stmt->fetchColumn();
        if ($supplierId === 0) {
            throw new \InvalidArgumentException("Client #$clientId nenalezen.");
        }

        $pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO projects
                (client_id, name, payment_due_days, project_number, contract_number,
                 budget_total, budget_yearly, budget_monthly, hourly_rate, currency_id, status,
                 requires_work_report_approval, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $clientId,
                (string) $data['name'],
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->saveBillingEmails($id, $data['billing_emails'] ?? []);

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $pdo = $this->db->pdo();
        // Supplier lookup pro currency scope (přes client projektu — nemění se)
        $stmt = $pdo->prepare('SELECT c.supplier_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $supplierId = (int) $stmt->fetchColumn();

        $pdo->beginTransaction();
        try {
            $sql = 'UPDATE projects SET
                    name = ?, payment_due_days = ?, project_number = ?, contract_number = ?,
                    budget_total = ?, budget_yearly = ?, budget_monthly = ?, hourly_rate = ?,
                    currency_id = ?, status = ?, requires_work_report_approval = ?, note = ?
                    WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                (string) $data['name'],
                (int) ($data['payment_due_days'] ?? 7),
                $this->nullable($data, 'project_number'),
                $this->nullable($data, 'contract_number'),
                $this->nullableNumber($data, 'budget_total'),
                $this->nullableNumber($data, 'budget_yearly'),
                $this->nullableNumber($data, 'budget_monthly'),
                (float) ($data['hourly_rate'] ?? 1500),
                $this->resolveCurrencyId($data, $supplierId),
                (string) ($data['status'] ?? 'active'),
                !empty($data['requires_work_report_approval']) ? 1 : 0,
                $this->nullable($data, 'note'),
                $id,
            ]);

            $this->saveBillingEmails($id, $data['billing_emails'] ?? []);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function archive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE projects SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function billingEmailsFor(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT position, email, label FROM project_billing_emails WHERE project_id = ? ORDER BY position'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'position' => (int) $r['position'],
            'email'    => $r['email'],
            'label'    => $r['label'],
        ], $rows);
    }

    private function saveBillingEmails(int $projectId, array $emails): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$projectId]);

        $stmt = $pdo->prepare(
            'INSERT INTO project_billing_emails (project_id, position, email, label) VALUES (?, ?, ?, ?)'
        );
        foreach ($emails as $entry) {
            if (!is_array($entry)) continue;
            $email = trim((string) ($entry['email'] ?? ''));
            $position = (int) ($entry['position'] ?? 0);
            if ($email === '' || $position < 1 || $position > 3) continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $label = trim((string) ($entry['label'] ?? '')) ?: null;
            $stmt->execute([$projectId, $position, $email, $label]);
        }
    }

    private function cast(array $row): array
    {
        if (isset($row['id']))               $row['id'] = (int) $row['id'];
        if (isset($row['client_id']))        $row['client_id'] = (int) $row['client_id'];
        if (isset($row['supplier_id']))      $row['supplier_id'] = (int) $row['supplier_id'];
        if (isset($row['payment_due_days'])) $row['payment_due_days'] = (int) $row['payment_due_days'];
        if (isset($row['hourly_rate']))      $row['hourly_rate'] = (float) $row['hourly_rate'];
        foreach (['budget_total', 'budget_yearly', 'budget_monthly'] as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $row[$f] !== null ? (float) $row[$f] : null;
            }
        }
        if (isset($row['currency_id']))      $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['requires_work_report_approval'])) {
            $row['requires_work_report_approval'] = (bool) $row['requires_work_report_approval'];
        }
        if (array_key_exists('revenue', $row)) {
            $row['revenue'] = (float) $row['revenue'];
        }
        if (array_key_exists('last_invoice_date', $row)) {
            $row['last_invoice_date'] = $row['last_invoice_date'] ?: null;
        }
        return $row;
    }

    /**
     * Resolve currency_id z `currency_id` (preferováno) nebo z `currency` (legacy code lookup).
     * Default = výchozí (is_default=1) řádek pro CZK v rámci supplier.
     * Pokud je dáno explicitní currency_id, ověří že patří danému supplier (anti cross-supplier).
     */
    private function resolveCurrencyId(array $data, int $supplierId): int
    {
        if (isset($data['currency_id'])) {
            $id = (int) $data['currency_id'];
            $check = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([$id, $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException("Currency #$id nepatří supplier #$supplierId.");
            }
            return $id;
        }
        $code = strtoupper((string) ($data['currency'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Currency not found: $code (supplier #$supplierId)");
        }
        return (int) $id;
    }

    private function nullable(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function nullableNumber(array $data, string $key): ?float
    {
        $v = $data[$key] ?? null;
        if ($v === null || $v === '') return null;
        return (float) $v;
    }
}
