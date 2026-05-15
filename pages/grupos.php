<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

// ── Criar grupo ────────────────────────────────────────────────
if ($action === 'criar_grupo') {
    $nome = trim($_POST['nome_grupo'] ?? '');
    if (!$nome) { flash('Nome do grupo obrigatório.', 'error'); redirect('grupos.php'); }
    $db->prepare("INSERT INTO grupos (nome,dono_id) VALUES (?,?)")->execute([$nome,$userId]);
    flash('Grupo criado.');
    redirect('grupos.php');
}

// ── Excluir grupo ──────────────────────────────────────────────
if ($action === 'excluir_grupo' && $id) {
    $st = $db->prepare("SELECT id FROM grupos WHERE id=? AND dono_id=?");
    $st->execute([$id,$userId]);
    if ($st->fetch()) {
        $db->prepare("DELETE FROM grupos WHERE id=?")->execute([$id]);
        flash('Grupo removido.');
    }
    redirect('grupos.php');
}

// ── Adicionar membro ───────────────────────────────────────────
if ($action === 'add_membro') {
    $grupoId = (int)($_POST['grupo_id'] ?? 0);
    $email   = trim($_POST['email_membro'] ?? '');
    $st = $db->prepare("SELECT id FROM grupos WHERE id=? AND dono_id=?");
    $st->execute([$grupoId,$userId]);
    if (!$st->fetch()) { flash('Grupo não encontrado.', 'error'); redirect('grupos.php'); }
    $st = $db->prepare("SELECT id FROM usuarios WHERE email=? AND ativo=1");
    $st->execute([$email]);
    $membro = $st->fetch();
    if (!$membro) { flash('Usuário não encontrado.', 'error'); redirect('grupos.php#grupo-'.$grupoId); }
    if ((int)$membro['id'] === $userId) { flash('Você não pode se adicionar ao próprio grupo.', 'error'); redirect('grupos.php#grupo-'.$grupoId); }
    try {
        $db->prepare("INSERT INTO grupo_membros (grupo_id,usuario_id) VALUES (?,?)")->execute([$grupoId,$membro['id']]);
        flash('Membro adicionado.');
    } catch (\PDOException $e) {
        flash('Usuário já é membro deste grupo.', 'error');
    }
    redirect('grupos.php#grupo-'.$grupoId);
}

// ── Salvar permissões do membro ────────────────────────────────
if ($action === 'salvar_permissoes') {
    $membroId = (int)($_POST['membro_id'] ?? 0);
    $st = $db->prepare("
        SELECT gm.id FROM grupo_membros gm
        JOIN grupos g ON g.id = gm.grupo_id
        WHERE gm.id=? AND g.dono_id=?
    ");
    $st->execute([$membroId,$userId]);
    if (!$st->fetch()) { flash('Acesso negado.', 'error'); redirect('grupos.php'); }

    $acesso       = ($_POST['acesso_imoveis'] ?? '') === 'selecionados' ? 'selecionados' : 'todos';
    $verValor     = isset($_POST['ver_valor'])     ? 1 : 0;
    $verPagamento = isset($_POST['ver_pagamento']) ? 1 : 0;
    $verOcupacao  = isset($_POST['ver_ocupacao'])  ? 1 : 0;
    $podeEscrever = isset($_POST['pode_escrever']) ? 1 : 0;

    $db->prepare("UPDATE grupo_membros SET acesso_imoveis=?,ver_valor=?,ver_pagamento=?,ver_ocupacao=?,pode_escrever=? WHERE id=?")
       ->execute([$acesso,$verValor,$verPagamento,$verOcupacao,$podeEscrever,$membroId]);

    $db->prepare("DELETE FROM grupo_membro_imoveis WHERE grupo_membro_id=?")->execute([$membroId]);
    if ($acesso === 'selecionados' && !empty($_POST['imoveis'])) {
        $stIm = $db->prepare("INSERT INTO grupo_membro_imoveis (grupo_membro_id,imovel_id) VALUES (?,?)");
        foreach ((array)$_POST['imoveis'] as $imId) {
            $stIm->execute([$membroId,(int)$imId]);
        }
    }
    flash('Permissões salvas.');
    redirect('grupos.php');
}

// ── Remover membro ─────────────────────────────────────────────
if ($action === 'remover_membro' && $id) {
    $st = $db->prepare("SELECT gm.id FROM grupo_membros gm JOIN grupos g ON g.id=gm.grupo_id WHERE gm.id=? AND g.dono_id=?");
    $st->execute([$id,$userId]);
    if ($st->fetch()) {
        $db->prepare("DELETE FROM grupo_membros WHERE id=?")->execute([$id]);
        flash('Membro removido.');
    }
    redirect('grupos.php');
}

// ── Dados para exibição ────────────────────────────────────────
$gruposStmt = $db->prepare("SELECT * FROM grupos WHERE dono_id=? ORDER BY nome");
$gruposStmt->execute([$userId]);
$grupos = $gruposStmt->fetchAll();

$meusImoveisStmt = $db->prepare("SELECT id,endereco FROM imoveis WHERE usuario_id=? ORDER BY endereco");
$meusImoveisStmt->execute([$userId]);
$meusImoveis = $meusImoveisStmt->fetchAll();

$editandoMembro = null;
if ($action === 'edit_membro' && $id) {
    $st = $db->prepare("
        SELECT gm.*, u.nome AS membro_nome, u.email AS membro_email
        FROM grupo_membros gm
        JOIN usuarios u ON u.id = gm.usuario_id
        JOIN grupos g ON g.id = gm.grupo_id
        WHERE gm.id=? AND g.dono_id=?
    ");
    $st->execute([$id,$userId]);
    $editandoMembro = $st->fetch();
    if ($editandoMembro) {
        $stSel = $db->prepare("SELECT imovel_id FROM grupo_membro_imoveis WHERE grupo_membro_id=?");
        $stSel->execute([$id]);
        $editandoMembro['imoveis_selecionados'] = array_column($stSel->fetchAll(), 'imovel_id');
    }
}

layoutHead('Grupos');
renderFlash();
?>
<div class="page-title">
    <h1>Grupos</h1>
</div>

<?php if ($editandoMembro): ?>
<div class="form-card" style="margin-bottom:28px">
    <h2 style="font-size:1rem;margin-bottom:16px">Permissões de <?= h($editandoMembro['membro_nome']) ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="salvar_permissoes">
        <input type="hidden" name="membro_id" value="<?= $editandoMembro['id'] ?>">
        <div class="form-grid">
            <div class="field span2">
                <label>Acesso a Imóveis</label>
                <select name="acesso_imoveis" id="acesso_imoveis" onchange="document.getElementById('sel-imoveis').style.display=this.value==='selecionados'?'block':'none'">
                    <option value="todos" <?= $editandoMembro['acesso_imoveis']==='todos'?'selected':'' ?>>Todos os imóveis</option>
                    <option value="selecionados" <?= $editandoMembro['acesso_imoveis']==='selecionados'?'selected':'' ?>>Imóveis selecionados</option>
                </select>
            </div>
            <div class="field span2" id="sel-imoveis" style="display:<?= $editandoMembro['acesso_imoveis']==='selecionados'?'block':'none' ?>">
                <label>Selecione os Imóveis</label>
                <div style="display:flex;flex-direction:column;gap:6px;max-height:160px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:10px">
                <?php foreach ($meusImoveis as $im): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer">
                        <input type="checkbox" name="imoveis[]" value="<?= $im['id'] ?>"
                            <?= in_array((int)$im['id'], $editandoMembro['imoveis_selecionados']) ? 'checked' : '' ?>>
                        <?= h($im['endereco']) ?>
                    </label>
                <?php endforeach ?>
                </div>
            </div>
            <div class="field">
                <label style="font-weight:600;margin-bottom:8px">O que pode visualizar</label>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer"><input type="checkbox" name="ver_ocupacao" <?= $editandoMembro['ver_ocupacao']?'checked':'' ?>> Status de ocupação</label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer"><input type="checkbox" name="ver_valor" <?= $editandoMembro['ver_valor']?'checked':'' ?>> Valor do aluguel</label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer"><input type="checkbox" name="ver_pagamento" <?= $editandoMembro['ver_pagamento']?'checked':'' ?>> Status de pagamento</label>
                </div>
            </div>
            <div class="field">
                <label style="font-weight:600;margin-bottom:8px">Permissão de escrita</label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer"><input type="checkbox" name="pode_escrever" <?= $editandoMembro['pode_escrever']?'checked':'' ?>> Pode registrar pagamentos</label>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Salvar Permissões</button>
            <a href="grupos.php" class="btn btn-ghost">Cancelar</a>
        </div>
    </form>
</div>
<?php endif ?>

<div class="form-card" style="margin-bottom:28px">
    <h2 style="font-size:1rem;margin-bottom:16px">Novo Grupo</h2>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end">
        <input type="hidden" name="action" value="criar_grupo">
        <div class="field" style="flex:1;margin:0">
            <label>Nome do grupo</label>
            <input name="nome_grupo" required placeholder="Ex: Família, Sócios">
        </div>
        <button class="btn btn-primary" type="submit">Criar</button>
    </form>
</div>

<?php if (!$grupos): ?>
<div class="empty"><p>Nenhum grupo criado ainda.</p></div>
<?php endif ?>

<?php foreach ($grupos as $grupo): ?>
<?php
$membrosStmt = $db->prepare("SELECT gm.*, u.nome AS membro_nome, u.email AS membro_email FROM grupo_membros gm JOIN usuarios u ON u.id=gm.usuario_id WHERE gm.grupo_id=?");
$membrosStmt->execute([$grupo['id']]);
$membros = $membrosStmt->fetchAll();
?>
<div class="table-wrap" style="margin-bottom:20px" id="grupo-<?= $grupo['id'] ?>">
    <div class="table-header">
        <h2><?= h($grupo['nome']) ?></h2>
        <a href="grupos.php?action=excluir_grupo&id=<?= $grupo['id'] ?>" class="btn btn-danger btn-sm"
           data-confirm="Excluir grupo '<?= h($grupo['nome']) ?>'? Os membros perderão o acesso.">Excluir</a>
    </div>

    <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
        <form method="POST" style="display:flex;gap:10px;align-items:flex-end">
            <input type="hidden" name="action" value="add_membro">
            <input type="hidden" name="grupo_id" value="<?= $grupo['id'] ?>">
            <div class="field" style="flex:1;margin:0">
                <label>Adicionar membro por email</label>
                <input type="email" name="email_membro" required placeholder="email@exemplo.com">
            </div>
            <button class="btn btn-ghost btn-sm" type="submit">Adicionar</button>
        </form>
    </div>

    <?php if ($membros): ?>
    <table>
        <thead><tr><th>Membro</th><th>Acesso</th><th>Ver Valor</th><th>Ver Pgto</th><th>Ver Ocup.</th><th>Escrever</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($membros as $m): ?>
        <tr>
            <td><?= h($m['membro_nome']) ?><br><small style="color:var(--muted)"><?= h($m['membro_email']) ?></small></td>
            <td><?= $m['acesso_imoveis'] === 'todos' ? 'Todos' : 'Selecionados' ?></td>
            <td><?= $m['ver_valor'] ? '✓' : '—' ?></td>
            <td><?= $m['ver_pagamento'] ? '✓' : '—' ?></td>
            <td><?= $m['ver_ocupacao'] ? '✓' : '—' ?></td>
            <td><?= $m['pode_escrever'] ? '✓' : '—' ?></td>
            <td>
                <div class="actions">
                    <a href="grupos.php?action=edit_membro&id=<?= $m['id'] ?>" class="btn btn-ghost btn-sm">Permissões</a>
                    <a href="grupos.php?action=remover_membro&id=<?= $m['id'] ?>" class="btn btn-danger btn-sm"
                       data-confirm="Remover <?= h($m['membro_nome']) ?> do grupo?">Remover</a>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty" style="padding:24px"><p>Nenhum membro ainda. Adicione pelo email acima.</p></div>
    <?php endif ?>
</div>
<?php endforeach ?>
<?php layoutFoot(); ?>
