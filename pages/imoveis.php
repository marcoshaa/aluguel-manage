<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'save') {
    $endereco = trim($_POST['endereco'] ?? '');
    $tipo     = $_POST['tipo'] ?? 'casa';
    $valor    = (float) str_replace(',', '.', $_POST['valor_aluguel'] ?? '0');
    $status   = $_POST['status'] ?? 'disponivel';
    $editId   = (int)($_POST['id'] ?? 0);

    if (!$endereco) { flash('Endereço obrigatório.', 'error'); redirect('imoveis.php'); }

    if ($editId) {
        $perm = permissoesImovel($userId, $editId);
        if (!$perm['pode_escrever']) { flash('Sem permissão para editar este imóvel.', 'error'); redirect('imoveis.php'); }
        $db->prepare("UPDATE imoveis SET endereco=?, tipo=?, valor_aluguel=?, status=? WHERE id=?")
           ->execute([$endereco, $tipo, $valor, $status, $editId]);
        flash('Imóvel atualizado.');
    } else {
        $db->prepare("INSERT INTO imoveis (usuario_id, endereco, tipo, valor_aluguel, status) VALUES (?,?,?,?,?)")
           ->execute([$userId, $endereco, $tipo, $valor, $status]);
        flash('Imóvel cadastrado.');
    }
    redirect('imoveis.php');
}

if ($action === 'delete' && $id) {
    $perm = permissoesImovel($userId, $id);
    if (!$perm['pode_escrever']) { flash('Sem permissão.', 'error'); redirect('imoveis.php'); }
    $db->prepare("DELETE FROM imoveis WHERE id=?")->execute([$id]);
    flash('Imóvel removido.');
    redirect('imoveis.php');
}

$editing = null;
if ($action === 'edit' && $id) {
    $st = $db->prepare("SELECT * FROM imoveis WHERE id=?");
    $st->execute([$id]);
    $editing = $st->fetch();
}

$visiveis = imoveisVisiveis($userId);
$imoveis  = [];
if ($visiveis) {
    $in      = implode(',', $visiveis);
    $imoveis = $db->query("SELECT * FROM imoveis WHERE id IN ({$in}) ORDER BY endereco")->fetchAll();
}

layoutHead('Imóveis');
renderFlash();
?>
<div class="page-title"><h1>Imóveis</h1></div>

<div class="form-card" style="margin-bottom:28px">
    <h2 style="margin-bottom:16px;font-size:1rem"><?= $editing ? 'Editar Imóvel' : 'Novo Imóvel' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
        <div class="form-grid">
            <div class="field span2">
                <label>Endereço</label>
                <input name="endereco" required value="<?= $editing ? h($editing['endereco']) : '' ?>" placeholder="Ex: Rua das Flores, 123">
            </div>
            <div class="field">
                <label>Tipo</label>
                <select name="tipo">
                    <?php foreach (['casa'=>'Casa','apto'=>'Apartamento','sala'=>'Sala Comercial'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($editing && $editing['tipo']===$v)?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field">
                <label>Valor do Aluguel (R$)</label>
                <input name="valor_aluguel" type="number" step="0.01" min="0" required value="<?= $editing ? $editing['valor_aluguel'] : '' ?>">
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="disponivel" <?= ($editing && $editing['status']==='disponivel')?'selected':'' ?>>Disponível</option>
                    <option value="alugado"    <?= ($editing && $editing['status']==='alugado')?'selected':'' ?>>Alugado</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary"><?= $editing ? 'Salvar' : 'Cadastrar' ?></button>
            <?php if ($editing): ?><a href="imoveis.php" class="btn btn-ghost">Cancelar</a><?php endif ?>
        </div>
    </form>
</div>

<div class="table-wrap">
    <div class="table-header"><h2>Imóveis Visíveis (<?= count($imoveis) ?>)</h2></div>
    <?php if ($imoveis): ?>
    <table>
        <thead><tr><th>Endereço</th><th>Tipo</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($imoveis as $im):
            $perm = permissoesImovel($userId, (int)$im['id']);
        ?>
        <tr>
            <td><?= h($im['endereco']) ?></td>
            <td><?= tipoLabel($im['tipo']) ?></td>
            <td><?= valorOuMascara((float)$im['valor_aluguel'], (bool)$perm['ver_valor']) ?></td>
            <td><?= $perm['ver_ocupacao'] ? statusImovelBadge($im['status']) : '—' ?></td>
            <td>
                <?php if ($perm['pode_escrever']): ?>
                <div class="actions">
                    <a href="imoveis.php?action=edit&id=<?= $im['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <a href="imoveis.php?action=delete&id=<?= $im['id'] ?>" class="btn btn-danger btn-sm"
                       data-confirm="Remover este imóvel?">Remover</a>
                </div>
                <?php else: ?>
                <span style="color:var(--muted);font-size:.8rem">somente leitura</span>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty"><p>Nenhum imóvel visível.</p></div>
    <?php endif ?>
</div>
<?php layoutFoot(); ?>
