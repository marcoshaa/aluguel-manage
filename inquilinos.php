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
    $nome    = trim($_POST['nome'] ?? '');
    $cpf     = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
    $tel     = trim($_POST['telefone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $nascto  = trim($_POST['data_nascimento'] ?? '') ?: null;
    $editId  = (int)($_POST['id'] ?? 0);

    if (!$nome || !$cpf) { flash('Nome e CPF são obrigatórios.', 'error'); redirect('inquilinos.php'); }

    if ($editId) {
        $db->prepare("UPDATE inquilinos SET nome=?, cpf=?, telefone=?, email=?, data_nascimento=? WHERE id=?")
           ->execute([$nome, $cpf, $tel, $email, $nascto, $editId]);
        flash('Inquilino atualizado.');
    } else {
        try {
            $db->prepare("INSERT INTO inquilinos (nome, cpf, telefone, email, data_nascimento) VALUES (?,?,?,?,?)")
               ->execute([$nome, $cpf, $tel, $email, $nascto]);
            flash('Inquilino cadastrado.');
        } catch (PDOException $e) {
            flash('CPF já cadastrado.', 'error');
        }
    }
    redirect('inquilinos.php');
}

if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM inquilinos WHERE id=?")->execute([$id]);
    flash('Inquilino removido.');
    redirect('inquilinos.php');
}

$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM inquilinos WHERE id=?");
    $stmt->execute([$id]);
    $editing = $stmt->fetch();
}

$inquilinos = $db->query("SELECT * FROM inquilinos ORDER BY nome")->fetchAll();

layoutHead('Inquilinos');
renderFlash();
?>

<div class="page-title"><h1>Inquilinos</h1></div>

<div class="form-card" style="margin-bottom:28px">
    <h2 style="margin-bottom:16px;font-size:1rem"><?= $editing ? 'Editar Inquilino' : 'Novo Inquilino' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
        <div class="form-grid">
            <div class="field span2">
                <label>Nome completo</label>
                <input name="nome" required value="<?= $editing ? h($editing['nome']) : '' ?>" placeholder="Nome do inquilino">
            </div>
            <div class="field">
                <label>CPF</label>
                <input name="cpf" required value="<?= $editing ? h($editing['cpf']) : '' ?>" placeholder="00000000000" maxlength="14">
            </div>
            <div class="field">
                <label>Telefone</label>
                <input name="telefone" value="<?= $editing ? h($editing['telefone'] ?? '') : '' ?>" placeholder="(00) 90000-0000">
            </div>
            <div class="field">
                <label>E-mail</label>
                <input name="email" type="email" value="<?= $editing ? h($editing['email'] ?? '') : '' ?>" placeholder="email@exemplo.com">
            </div>
            <div class="field">
                <label>Data de Nascimento</label>
                <input name="data_nascimento" type="date" value="<?= $editing ? h($editing['data_nascimento'] ?? '') : '' ?>">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar Alterações' : 'Cadastrar' ?></button>
            <?php if ($editing): ?><a href="inquilinos.php" class="btn btn-ghost">Cancelar</a><?php endif ?>
        </div>
    </form>
</div>

<div class="table-wrap">
    <div class="table-header"><h2>Inquilinos (<?= count($inquilinos) ?>)</h2></div>
    <?php if ($inquilinos): ?>
    <table>
        <thead><tr><th>Nome</th><th>CPF</th><th>Telefone</th><th>E-mail</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($inquilinos as $iq): ?>
        <tr>
            <td><?= h($iq['nome']) ?></td>
            <td><?= h($iq['cpf']) ?></td>
            <td><?= h($iq['telefone'] ?? '—') ?></td>
            <td><?= h($iq['email'] ?? '—') ?></td>
            <td>
                <div class="actions">
                    <a href="inquilinos.php?action=edit&id=<?= $iq['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <a href="inquilinos.php?action=delete&id=<?= $iq['id'] ?>" class="btn btn-danger btn-sm"
                       data-confirm="Remover este inquilino?">Remover</a>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty"><div class="empty-icon">👤</div><p>Nenhum inquilino cadastrado.</p></div>
    <?php endif ?>
</div>

<?php layoutFoot(); ?>
