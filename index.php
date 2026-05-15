<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/visibilidade.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$db       = getDB();
$userId   = (int)$_SESSION['user_id'];
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';

$totalImoveis    = count($visiveis);
$imoveisAlugados = $visiveis ? (int) $db->query("SELECT COUNT(*) FROM imoveis WHERE id IN ({$inClause}) AND status='alugado'")->fetchColumn() : 0;
$totalInquilinos = (int) $db->query("SELECT COUNT(*) FROM inquilinos")->fetchColumn();
$contratosAtivos = $visiveis ? (int) $db->query("SELECT COUNT(*) FROM contratos WHERE ativo=1 AND imovel_id IN ({$inClause})")->fetchColumn() : 0;

$mesAtual   = date('Y-m');
$atrasados  = $visiveis ? (int) $db->query("SELECT COUNT(*) FROM pagamentos p JOIN contratos c ON c.id=p.contrato_id WHERE p.mes_referencia='{$mesAtual}' AND p.status='atrasado' AND c.imovel_id IN ({$inClause})")->fetchColumn() : 0;
$receitaMes = $visiveis ? (float) ($db->query("SELECT COALESCE(SUM(p.valor_pago),0) FROM pagamentos p JOIN contratos c ON c.id=p.contrato_id WHERE p.mes_referencia='{$mesAtual}' AND p.status='pago' AND c.imovel_id IN ({$inClause})")->fetchColumn() ?? 0) : 0;

// Últimos pagamentos
$ultimos = $visiveis ? $db->query("
    SELECT p.mes_referencia, p.status, p.valor_pago, p.data_pagamento,
           q.nome AS inquilino, i.endereco
    FROM pagamentos p
    JOIN contratos c ON c.id = p.contrato_id
    JOIN inquilinos q ON q.id = c.inquilino_id
    JOIN imoveis i ON i.id = c.imovel_id
    WHERE c.imovel_id IN ({$inClause})
    ORDER BY p.id DESC LIMIT 8
")->fetchAll() : [];

layoutHead('Dashboard');
?>

<div class="cards">
    <div class="card">
        <div class="card-title">Imóveis</div>
        <div class="card-value"><?= $totalImoveis ?></div>
        <div class="card-sub"><?= $imoveisAlugados ?> alugado(s)</div>
    </div>
    <div class="card">
        <div class="card-title">Inquilinos</div>
        <div class="card-value"><?= $totalInquilinos ?></div>
    </div>
    <div class="card success">
        <div class="card-title">Contratos Ativos</div>
        <div class="card-value"><?= $contratosAtivos ?></div>
    </div>
    <div class="card success">
        <div class="card-title">Receita <?= mesBR($mesAtual) ?></div>
        <div class="card-value" style="font-size:1.4rem"><?= moeda($receitaMes) ?></div>
    </div>
    <div class="card <?= $atrasados > 0 ? 'danger' : '' ?>">
        <div class="card-title">Atrasados</div>
        <div class="card-value"><?= $atrasados ?></div>
        <div class="card-sub">este mês</div>
    </div>
</div>

<div class="table-wrap">
    <div class="table-header">
        <h2>Últimos Pagamentos</h2>
        <a href="pages/pagamentos.php" class="btn btn-ghost btn-sm">Ver todos</a>
    </div>
    <?php if ($ultimos): ?>
    <table>
        <thead>
            <tr>
                <th>Inquilino</th>
                <th>Imóvel</th>
                <th>Mês</th>
                <th>Valor</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ultimos as $p): ?>
            <tr>
                <td><?= h($p['inquilino']) ?></td>
                <td><?= h($p['endereco']) ?></td>
                <td><?= mesBR($p['mes_referencia']) ?></td>
                <td><?= $p['valor_pago'] ? moeda((float)$p['valor_pago']) : '—' ?></td>
                <td><?= statusPagBadge($p['status']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty">
        <div class="empty-icon">💰</div>
        <p>Nenhum pagamento registrado ainda.</p>
    </div>
    <?php endif ?>
</div>

<?php layoutFoot(); ?>
