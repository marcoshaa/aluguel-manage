<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();
$db     = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'save') {
    $nome   = trim($_POST['nome'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $isAdm  = isset($_POST['is_admin']) ? 1 : 0;
    $editId = (int)($_POST['id'] ?? 0);
    $senha  = $_POST['senha'] ?? '';

    if (!$nome || !$email) { flash('Nome e email são obrigatórios.', 'error'); redirect('usuarios.php'); }

    if ($editId) {
        if ($senha) {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $db->prepare("UPDATE usuarios SET nome=?,email=?,is_admin=?,senha=? WHERE id=?")
               ->execute([$nome,$email,$isAdm,$hash,$editId]);
        } else {
            $db->prepare("UPDATE usuarios SET nome=?,email=?,is_admin=? WHERE id=?")
               ->execute([$nome,$email,$isAdm,$editId]);
        }
        flash('Usuário atualizado.');
    } else {
        if (!$senha) { flash('Senha obrigatória para novo usuário.', 'error'); redirect('usuarios.php'); }
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO usuarios (nome,email,senha,is_admin) VALUES (?,?,?,?)")
           ->execute([$nome,$email,$hash,$isAdm]);
        flash('Usuário criado.');
    }
    redirect('usuarios.php');
}

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE usuarios SET ativo = IF(ativo=1,0,1) WHERE id=?")->execute([$id]);
    flash('Status do usuário alterado.');
    redirect('usuarios.php');
}

$editing = null;
if ($action === 'edit' && $id) {
    $st = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $st->execute([$id]);
    $editing = $st->fetch();
}

$usuarios = $db->query("SELECT * FROM usuarios ORDER BY nome")->fetchAll();

layoutHead('Usuários');
renderFlash();
?>
<div class="page-title">
    <h1>Usuários</h1>
</div>

<div class="form-card" style="margin-bottom:28px">
    <h2 style="margin-bottom:16px;font-size:1rem"><?= $editing ? 'Editar Usuário' : 'Novo Usuário' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? $editing['id'] : '' ?>">
        <div class="form-grid">
            <div class="field">
                <label>Nome</label>
                <input name="nome" required value="<?= $editing ? h($editing['nome']) : '' ?>">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required value="<?= $editing ? h($editing['email']) : '' ?>">
            </div>
            <div class="field">
                <label>Senha <?= $editing ? '<span style="font-weight:400;color:var(--muted)">(deixe em branco para manter)</span>' : '' ?></label>
                <input type="password" name="senha" <?= $editing ? '' : 'required' ?> minlength="3">
            </div>
            <div class="field" style="justify-content:flex-end;padding-bottom:4px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_admin" value="1" <?= ($editing && $editing['is_admin']) ? 'checked' : '' ?>>
                    Administrador
                </label>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar' : 'Criar Usuário' ?></button>
            <?php if ($editing): ?><a href="usuarios.php" class="btn btn-ghost">Cancelar</a><?php endif ?>
        </div>
    </form>
</div>

<div class="table-wrap">
    <div class="table-header"><h2>Todos os Usuários (<?= count($usuarios) ?>)</h2></div>
    <table>
        <thead><tr><th>Nome</th><th>Email</th><th>Admin</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= h($u['nome']) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><?= $u['is_admin'] ? '<span class="badge badge-alugado">Admin</span>' : '—' ?></td>
            <td><?= $u['ativo'] ? '<span class="badge badge-pago">Ativo</span>' : '<span class="badge badge-atrasado">Inativo</span>' ?></td>
            <td>
                <div class="actions">
                    <a href="usuarios.php?action=edit&id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <a href="usuarios.php?action=toggle&id=<?= $u['id'] ?>" class="btn btn-sm <?= $u['ativo'] ? 'btn-danger' : 'btn-ghost' ?>"
                       data-confirm="<?= $u['ativo'] ? 'Desativar este usuário?' : 'Reativar este usuário?' ?>">
                       <?= $u['ativo'] ? 'Desativar' : 'Reativar' ?>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php layoutFoot(); ?>
