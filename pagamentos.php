<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$db = getDB();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Gerar cobranças do mês atual para contratos ativos (se ainda não existirem)
$mesAtual = date('Y-m');
$ativosStmt = $db->query("SELECT id, dia_vencimento FROM contratos WHERE ativo=1");
foreach ($ativosStmt as $ct) {
    $exists = $db->prepare("SELECT id FROM pagamentos WHERE contrato_id=? AND mes_referencia=?");
    $exists->execute([$ct['id'], $mesAtual]);
    if (!$exists->fetch()) {
        $status = calcularStatusPagamento($mesAtual, (int)$ct['dia_vencimento']);
        $db->prepare("INSERT INTO pagamentos (contrato_id, mes_referencia, status) VALUES (?,?,?)")
           ->execute([$ct['id'], $mesAtual, $status]);
    }
}

if ($action === 'pagar') {
    $pagId    = (int)($_POST['pagamento_id'] ?? 0);
    $valor    = (float) str_replace(',', '.', $_POST['valor_pago'] ?? '0');
    $dataPag  = trim($_POST['data_pagamento'] ?? date('Y-m-d'));
    if ($pagId && $valor > 0) {
        $db->prepare("UPDATE pagamentos SET valor_pago=?, data_pagamento=?, status='pago' WHERE id=?")
           ->execute([$valor, $dataPag, $pagId]);
        flash('Pagamento registrado!');
    } else {
        flash('Informe um valor válido.', 'error');
    }
    redirect('pagamentos.php');
}

if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM pagamentos WHERE id=?")->execute([$id]);
    flash('Registro removido.');
    redirect('pagamentos.php');
}

// Filtro de mês
$filtroMes = $_GET['mes'] ?? $mesAtual;

$pagamentos = $db->prepare("
    SELECT p.*, c.dia_vencimento, c.valor_mensal,
           q.nome AS inquilino, i.endereco
    FROM pagamentos p
    JOIN contratos c ON c.id = p.contrato_id
    JOIN inquilinos q ON q.id = c.inquilino_id
    JOIN imoveis i ON i.id = c.imovel_id
    WHERE p.mes_referencia = ?
    ORDER BY p.status DESC, q.nome
");
$pagamentos->execute([$filtroMes]);
$pagamentos = $pagamentos->fetchAll();

// Atualizar status de atrasados
foreach ($pagamentos as $pg) {
    if ($pg['status'] === 'pendente') {
        $novo = calcularStatusPagamento($pg['mes_referencia'], (int)$pg['dia_vencimento']);
        if ($novo !== 'pendente') {
            $db->prepare("UPDATE pagamentos SET status=? WHERE id=?")->execute([$novo, $pg['id']]);
        }
    }
}

// Meses disponíveis para filtro
$meses = $db->query("SELECT DISTINCT mes_referencia FROM pagamentos ORDER BY mes_referencia DESC")->fetchAll(PDO::FETCH_COLUMN);

layoutHead('Pagamentos');
renderFlash();
?>

<div class="page-title">
    <h1>Pagamentos</h1>
    <div style="display:flex;gap:8px;align-items:center">
        <label style="font-size:.9rem;color:var(--muted)">Mês:</label>
        <form method="GET" style="display:flex;gap:6px">
            <select name="mes" onchange="this.form.submit()" style="padding:6px 10px">
                <?php foreach ($meses as $m): ?>
                <option value="<?= $m ?>" <?= $m === $filtroMes ? 'selected' : '' ?>><?= mesBR($m) ?></option>
                <?php endforeach ?>
            </select>
        </form>
    </div>
</div>

<div class="table-wrap">
    <div class="table-header"><h2><?= mesBR($filtroMes) ?> — <?= count($pagamentos) ?> cobranças</h2></div>
    <?php if ($pagamentos): ?>
    <table>
        <thead><tr><th>Inquilino</th><th>Imóvel</th><th>Vencimento</th><th>Valor Previsto</th><th>Valor Pago</th><th>Data Pagto</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($pagamentos as $pg): ?>
        <tr>
            <td><?= h($pg['inquilino']) ?></td>
            <td><?= h($pg['endereco']) ?></td>
            <td>Dia <?= $pg['dia_vencimento'] ?></td>
            <td><?= moeda((float)$pg['valor_mensal']) ?></td>
            <td><?= $pg['valor_pago'] ? moeda((float)$pg['valor_pago']) : '—' ?></td>
            <td><?= $pg['data_pagamento'] ? dataBR($pg['data_pagamento']) : '—' ?></td>
            <td><?= statusPagBadge($pg['status']) ?></td>
            <td>
                <?php if ($pg['status'] !== 'pago'): ?>
                <form method="POST" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="action" value="pagar">
                    <input type="hidden" name="pagamento_id" value="<?= $pg['id'] ?>">
                    <input type="number" name="valor_pago" step="0.01" placeholder="<?= $pg['valor_mensal'] ?>"
                           value="<?= $pg['valor_mensal'] ?>" style="width:110px;padding:5px 8px;font-size:.85rem">
                    <input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" style="padding:5px 8px;font-size:.85rem">
                    <button type="submit" class="btn btn-primary btn-sm">Registrar</button>
                </form>
                <?php else: ?>
                <a href="pagamentos.php?action=delete&id=<?= $pg['id'] ?>" class="btn btn-ghost btn-sm"
                   data-confirm="Desfazer pagamento?">Desfazer</a>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty"><div class="empty-icon">💰</div><p>Nenhuma cobrança para este mês.</p></div>
    <?php endif ?>
</div>

<?php layoutFoot(); ?>
