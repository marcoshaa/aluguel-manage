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

if ($action === 'save') {
    $imovelId    = (int)($_POST['imovel_id'] ?? 0);
    $inquilinoId = (int)($_POST['inquilino_id'] ?? 0);
    $inicio      = trim($_POST['data_inicio'] ?? '');
    $fim         = trim($_POST['data_fim'] ?? '') ?: null;
    $valor       = (float) str_replace(',', '.', $_POST['valor_mensal'] ?? '0');
    $diaVenc     = (int)($_POST['dia_vencimento'] ?? 10);
    $indice      = $_POST['indice_reajuste'] ?? 'fixo';
    $ativo       = (int)($_POST['ativo'] ?? 1);
    $editId      = (int)($_POST['id'] ?? 0);

    if (!$imovelId || !$inquilinoId || !$inicio || !$valor) {
        flash('Preencha todos os campos obrigatórios.', 'error');
        redirect('contratos.php');
    }

    if ($editId) {
        $db->prepare("UPDATE contratos SET imovel_id=?, inquilino_id=?, data_inicio=?, data_fim=?, valor_mensal=?, dia_vencimento=?, indice_reajuste=?, ativo=? WHERE id=?")
           ->execute([$imovelId, $inquilinoId, $inicio, $fim, $valor, $diaVenc, $indice, $ativo, $editId]);
        flash('Contrato atualizado.');
    } else {
        $db->prepare("INSERT INTO contratos (imovel_id, inquilino_id, data_inicio, data_fim, valor_mensal, dia_vencimento, indice_reajuste) VALUES (?,?,?,?,?,?,?)")
           ->execute([$imovelId, $inquilinoId, $inicio, $fim, $valor, $diaVenc, $indice]);
        // Marcar imóvel como alugado
        $db->prepare("UPDATE imoveis SET status='alugado' WHERE id=?")->execute([$imovelId]);
        flash('Contrato criado com sucesso.');
    }
    redirect('contratos.php');
}

if ($action === 'encerrar' && $id) {
    $contrato = $db->prepare("SELECT * FROM contratos WHERE id=?");
    $contrato->execute([$id]);
    $c = $contrato->fetch();
    if ($c) {
        $db->prepare("UPDATE contratos SET ativo=0 WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE imoveis SET status='disponivel' WHERE id=?")->execute([$c['imovel_id']]);
        flash('Contrato encerrado. Imóvel marcado como disponível.');
    }
    redirect('contratos.php');
}

if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM contratos WHERE id=?")->execute([$id]);
    flash('Contrato removido.');
    redirect('contratos.php');
}

$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM contratos WHERE id=?");
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
}

$contratos = $db->query("
    SELECT c.*, i.endereco, q.nome AS inquilino
    FROM contratos c
    JOIN imoveis i ON i.id = c.imovel_id
    JOIN inquilinos q ON q.id = c.inquilino_id
    ORDER BY c.ativo DESC, c.data_inicio DESC
")->fetchAll();

$imoveis    = $db->query("SELECT * FROM imoveis ORDER BY endereco")->fetchAll();
$inquilinos = $db->query("SELECT * FROM inquilinos ORDER BY nome")->fetchAll();

layoutHead('Contratos');
renderFlash();
?>

<div class="page-title"><h1>Contratos</h1></div>

<div class="form-card" style="margin-bottom:28px">
    <h2 style="margin-bottom:16px;font-size:1rem"><?= $editing ? 'Editar Contrato' : 'Novo Contrato' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
        <div class="form-grid">
            <div class="field">
                <label>Imóvel</label>
                <select name="imovel_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($imoveis as $im): ?>
                    <option value="<?= $im['id'] ?>" <?= ($editing && $editing['imovel_id']==$im['id']) ? 'selected' : '' ?>>
                        <?= h($im['endereco']) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label>Inquilino</label>
                <select name="inquilino_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($inquilinos as $iq): ?>
                    <option value="<?= $iq['id'] ?>" <?= ($editing && $editing['inquilino_id']==$iq['id']) ? 'selected' : '' ?>>
                        <?= h($iq['nome']) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label>Data de Início</label>
                <input name="data_inicio" type="date" required value="<?= $editing ? h($editing['data_inicio']) : '' ?>">
            </div>
            <div class="field">
                <label>Data de Fim (opcional)</label>
                <input name="data_fim" type="date" value="<?= $editing ? h($editing['data_fim'] ?? '') : '' ?>">
            </div>
            <div class="field">
                <label>Valor Mensal (R$)</label>
                <input name="valor_mensal" type="number" step="0.01" min="0" required value="<?= $editing ? $editing['valor_mensal'] : '' ?>" placeholder="1500.00">
            </div>
            <div class="field">
                <label>Dia de Vencimento</label>
                <input name="dia_vencimento" type="number" min="1" max="28" value="<?= $editing ? $editing['dia_vencimento'] : '10' ?>">
            </div>
            <div class="field">
                <label>Índice de Reajuste</label>
                <select name="indice_reajuste">
                    <?php foreach (['fixo'=>'Fixo','IGPM'=>'IGPM','IPCA'=>'IPCA'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($editing && $editing['indice_reajuste']===$v) ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if ($editing): ?>
            <div class="field">
                <label>Status</label>
                <select name="ativo">
                    <option value="1" <?= $editing['ativo'] ? 'selected' : '' ?>>Ativo</option>
                    <option value="0" <?= !$editing['ativo'] ? 'selected' : '' ?>>Encerrado</option>
                </select>
            </div>
            <?php endif ?>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar' : 'Criar Contrato' ?></button>
            <?php if ($editing): ?><a href="contratos.php" class="btn btn-ghost">Cancelar</a><?php endif ?>
        </div>
    </form>
</div>

<div class="table-wrap">
    <div class="table-header"><h2>Contratos (<?= count($contratos) ?>)</h2></div>
    <?php if ($contratos): ?>
    <table>
        <thead><tr><th>Imóvel</th><th>Inquilino</th><th>Valor</th><th>Vencimento</th><th>Período</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($contratos as $ct): ?>
        <tr>
            <td><?= h($ct['endereco']) ?></td>
            <td><?= h($ct['inquilino']) ?></td>
            <td><?= moeda((float)$ct['valor_mensal']) ?></td>
            <td>Dia <?= $ct['dia_vencimento'] ?></td>
            <td><?= dataBR($ct['data_inicio']) ?> <?= $ct['data_fim'] ? '→ ' . dataBR($ct['data_fim']) : '' ?></td>
            <td><?= $ct['ativo'] ? '<span class="badge badge-pago">Ativo</span>' : '<span class="badge badge-atrasado">Encerrado</span>' ?></td>
            <td>
                <div class="actions">
                    <a href="contratos.php?action=edit&id=<?= $ct['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <?php if ($ct['ativo']): ?>
                    <a href="contratos.php?action=encerrar&id=<?= $ct['id'] ?>" class="btn btn-ghost btn-sm"
                       data-confirm="Encerrar este contrato?">Encerrar</a>
                    <?php endif ?>
                    <a href="contratos.php?action=delete&id=<?= $ct['id'] ?>" class="btn btn-danger btn-sm"
                       data-confirm="Remover este contrato permanentemente?">Remover</a>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty"><div class="empty-icon">📄</div><p>Nenhum contrato cadastrado.</p></div>
    <?php endif ?>
</div>

<?php layoutFoot(); ?>
