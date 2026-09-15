<?php

namespace RTOFLOW\Database;

if (!defined('ABSPATH')) exit;

/**
 * Fluent QueryBuilder wrapping wpdb
 *
 * Usage:
 *   $rows = (new QueryBuilder($wpdb))
 *       ->table('rto_leads l')
 *       ->select('l.*', 'u.display_name as client_name')
 *       ->left_join('users u', 'u.ID=l.client_id')
 *       ->where('l.status', '=', 'created')
 *       ->order_by('l.created_at', 'DESC')
 *       ->paginate(25, 1);
 */
class QueryBuilder
{
    private string $tbl   = '';
    private array  $sel   = ['*'];
    private array  $where = [];
    private array  $joins = [];
    private string $order = '';
    private int    $lim   = 0;
    private int    $off   = 0;

    public function __construct(private \wpdb $db) {}

    public function table(string $t): self
    {
        $c = clone $this;
        $c->tbl   = $this->db->prefix . $t;
        $c->sel   = ['*']; $c->where = []; $c->joins = [];
        $c->order = ''; $c->lim = 0; $c->off = 0;
        return $c;
    }

    public function select(string ...$cols): self { $this->sel = $cols; return $this; }

    public function where(string $col, string $op, mixed $val): self
    {
        $fmt = is_int($val) ? '%d' : (is_float($val) ? '%f' : '%s');
        $this->where[] = $this->db->prepare("$col $op $fmt", $val);
        return $this;
    }

    public function where_raw(string $sql): self { $this->where[] = $sql; return $this; }

    public function where_in(string $col, array $vals): self
    {
        if (empty($vals)) return $this;
        $ph = implode(',', array_fill(0, count($vals), '%s'));
        $this->where[] = $this->db->prepare("$col IN ($ph)", ...$vals);
        return $this;
    }

    public function left_join(string $t, string $on): self
    {
        $this->joins[] = "LEFT JOIN {$this->db->prefix}{$t} ON $on";
        return $this;
    }

    public function order_by(string $col, string $dir = 'ASC'): self
    {
        $this->order = "ORDER BY $col " . ($dir === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    public function limit(int $n): self  { $this->lim = $n; return $this; }
    public function offset(int $n): self { $this->off = $n; return $this; }

    public function get(): array
    {
        return $this->db->get_results($this->sql_select(), ARRAY_A) ?: [];
    }

    public function first(): ?array { return $this->limit(1)->get()[0] ?? null; }

    public function count(): int
    {
        $j = implode(' ', $this->joins);
        $w = $this->sql_where();
        return (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->tbl} $j $w");
    }

    public function paginate(int $per, int $page): array
    {
        $total = $this->count();
        $data  = $this->limit($per)->offset(($page - 1) * $per)->get();
        return [
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $per,
            'current_page' => $page,
            'last_page'    => max(1, (int)ceil($total / $per)),
        ];
    }

    public function insert(array $data): int
    {
        // ENTERPRISE GAP FIX (Phase 8, item — "CHECK constraints silently
        // skipped on older MySQL/MariaDB"): app-level fallback validation
        // for the same fields the DB migration's CHECK constraints cover,
        // enforced here regardless of whether the DB actually applied them.
        \RTOFLOW\Support\DataIntegrityGuard::check($this->tbl, $data, $this->db->prefix);
        $this->db->insert($this->tbl, $data);
        return (int)$this->db->insert_id;
    }

    public function update(array $data, array $where): bool
    {
        \RTOFLOW\Support\DataIntegrityGuard::check($this->tbl, $data, $this->db->prefix);
        return (bool)$this->db->update($this->tbl, $data, $where);
    }

    public function delete(array $where): bool
    {
        return (bool)$this->db->delete($this->tbl, $where);
    }

    private function sql_select(): string
    {
        $cols = implode(', ', $this->sel);
        $j    = implode(' ', $this->joins);
        $w    = $this->sql_where();
        $sql  = "SELECT $cols FROM {$this->tbl} $j $w {$this->order}";
        if ($this->lim) $sql .= " LIMIT {$this->lim}";
        if ($this->off) $sql .= " OFFSET {$this->off}";
        return $sql;
    }

    private function sql_where(): string
    {
        return $this->where ? 'WHERE ' . implode(' AND ', $this->where) : '';
    }
}
