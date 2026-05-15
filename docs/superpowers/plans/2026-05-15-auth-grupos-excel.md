# Auth, Grupos e Excel Export — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar recuperação de senha, gerenciamento de usuários, sistema de grupos com matriz de permissões, exportação Excel via chat IA, e reorganizar páginas em `pages/`.

**Architecture:** PHP vanilla com PDO/MySQL. Todas as páginas autenticadas migram para `pages/`. Visibilidade de dados centralizada em `includes/visibilidade.php`. Grupos controlam permissões por membro via matriz de flags na tabela `grupo_membros`.

**Tech Stack:** PHP 7.4+, MySQL (FreeSQLDatabase), HTML/CSS/JS vanilla, PHP `mail()` para recuperação de senha.

---

## Mapa de Arquivos

| Arquivo | Ação |
|---|---|
| `includes/db.php` | Modificar — novas tabelas + colunas + usuário padrão |
| `includes/auth.php` | Modificar — adicionar `requireAdmin()`, `isAdmin()` |
| `includes/layout.php` | Modificar — links para `pages/`, sidebar grupos/usuarios |
| `includes/visibilidade.php` | Criar — funções de visibilidade por usuário |
| `includes/gemini.php` | Modificar — filtra dados por visibilidade + detecta export |
| `pages/imoveis.php` | Mover + Modificar — ownership + visibilidade |
| `pages/inquilinos.php` | Mover |
| `pages/contratos.php` | Mover + Modificar — filtra por imoveis visíveis |
| `pages/pagamentos.php` | Mover + Modificar — filtra por imoveis visíveis + pode_escrever |
| `pages/chat.php` | Mover |
| `pages/perfil.php` | Mover |
| `pages/usuarios.php` | Criar — admin gerencia usuários |
| `pages/grupos.php` | Criar — dono gerencia grupos e membros |
| `pages/recuperar-senha.php` | Criar — solicita token |
| `pages/redefinir-senha.php` | Criar — redefine senha via token |
| `api/export.php` | Criar — gera .xls respeitando visibilidade |
| `api/chat.php` | Modificar — detecta [EXPORTAR:tipo:filtro] |

---

## Task 1: Migrations do banco

**Files:**
- Modify: `includes/db.php`

- [ ] **Step 1: Adicionar colunas novas e novas tabelas em `runMigrations()`**

Substitua toda a função `runMigrations` em `includes/db.php`:

```php
function runMigrations(PDO $pdo): void {
    // ── Tabelas base ──────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        senha VARCHAR(255) NOT NULL,
        gemini_api_key VARCHAR(255),
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Adiciona colunas que podem não existir ainda (idempotente via information_schema)
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_admin', $cols)) $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
    if (!in_array('ativo', $cols))    $pdo->exec("ALTER TABLE usuarios ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");

    // Garante usuário padrão
    $exist = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $exist->execute(['m.andrade.assis@gmail.com']);
    $u = $exist->fetch();
    $hash = password_hash('123', PASSWORD_DEFAULT);
    if ($u) {
        $pdo->prepare("UPDATE usuarios SET senha=?, is_admin=1 WHERE id=?")->execute([$hash, $u['id']]);
    } else {
        $pdo->prepare("INSERT INTO usuarios (nome,email,senha,is_admin) VALUES (?,?,?,1)")
            ->execute(['Marcos Andrade','m.andrade.assis@gmail.com',$hash]);
    }
    // Torna admin o primeiro usuário existente caso ainda não haja admin
    $pdo->exec("UPDATE usuarios SET is_admin=1 ORDER BY id LIMIT 1");

    // ── Imóveis ───────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS imoveis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT,
        endereco VARCHAR(255) NOT NULL,
        tipo ENUM('casa','apto','sala') NOT NULL,
        valor_aluguel DECIMAL(10,2) NOT NULL,
        status ENUM('disponivel','alugado') NOT NULL DEFAULT 'disponivel',
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $colsIm = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'imoveis'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('usuario_id', $colsIm)) {
        $pdo->exec("ALTER TABLE imoveis ADD COLUMN usuario_id INT AFTER id");
        // Atribui imóveis órfãos ao primeiro admin
        $adminId = $pdo->query("SELECT id FROM usuarios WHERE is_admin=1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId) $pdo->exec("UPDATE imoveis SET usuario_id={$adminId} WHERE usuario_id IS NULL");
    }

    // ── Demais tabelas ────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS inquilinos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        cpf VARCHAR(14) NOT NULL UNIQUE,
        telefone VARCHAR(20),
        email VARCHAR(150),
        data_nascimento DATE,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imovel_id INT NOT NULL,
        inquilino_id INT NOT NULL,
        data_inicio DATE NOT NULL,
        data_fim DATE,
        valor_mensal DECIMAL(10,2) NOT NULL,
        dia_vencimento TINYINT NOT NULL DEFAULT 10,
        indice_reajuste ENUM('IGPM','IPCA','fixo') NOT NULL DEFAULT 'fixo',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        FOREIGN KEY (imovel_id) REFERENCES imoveis(id),
        FOREIGN KEY (inquilino_id) REFERENCES inquilinos(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contrato_id INT NOT NULL,
        mes_referencia VARCHAR(7) NOT NULL,
        valor_pago DECIMAL(10,2),
        data_pagamento DATE,
        status ENUM('pago','pendente','atrasado') NOT NULL DEFAULT 'pendente',
        FOREIGN KEY (contrato_id) REFERENCES contratos(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Grupos ────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS grupos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        dono_id INT NOT NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (dono_id) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS grupo_membros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grupo_id INT NOT NULL,
        usuario_id INT NOT NULL,
        acesso_imoveis ENUM('todos','selecionados') NOT NULL DEFAULT 'todos',
        ver_valor      TINYINT(1) NOT NULL DEFAULT 0,
        ver_pagamento  TINYINT(1) NOT NULL DEFAULT 0,
        ver_ocupacao   TINYINT(1) NOT NULL DEFAULT 1,
        pode_escrever  TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_grupo_membro (grupo_id, usuario_id),
        FOREIGN KEY (grupo_id)   REFERENCES grupos(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS grupo_membro_imoveis (
        grupo_membro_id INT NOT NULL,
        imovel_id INT NOT NULL,
        PRIMARY KEY (grupo_membro_id, imovel_id),
        FOREIGN KEY (grupo_membro_id) REFERENCES grupo_membros(id) ON DELETE CASCADE,
        FOREIGN KEY (imovel_id)       REFERENCES imoveis(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Recuperação de senha ──────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS recuperacao_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at TIMESTAMP NOT NULL,
        usado TINYINT(1) NOT NULL DEFAULT 0,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
```

- [ ] **Step 2: Testar migration**

Inicie o servidor e acesse `http://localhost:8000`.
Expected: página carrega sem erros 500. No banco (FreeSQLDatabase phpMyAdmin), verificar que as tabelas `grupos`, `grupo_membros`, `grupo_membro_imoveis`, `recuperacao_tokens` foram criadas e `imoveis` agora tem coluna `usuario_id`.

- [ ] **Step 3: Commit**

```bash
git add includes/db.php
git commit -m "feat: migrations — grupos, recuperacao_tokens, usuario_id em imoveis"
```

---

## Task 2: Mover páginas para `pages/`

**Files:**
- Move: todos os `.php` da raiz (exceto index, login, logout, config) → `pages/`
- Modify: `includes/layout.php` — atualiza links do sidebar

- [ ] **Step 1: Criar pasta e mover arquivos**

```bash
mkdir -p E:/work/pessoal/aluguel-manager/pages
cd E:/work/pessoal/aluguel-manager
mv imoveis.php inquilinos.php contratos.php pagamentos.php chat.php perfil.php pages/
```

- [ ] **Step 2: Corrigir os `require_once` dentro de cada arquivo movido**

Em cada arquivo em `pages/`, o caminho dos includes muda de `__DIR__ . '/includes/...'` para `__DIR__ . '/../includes/...'`. Rode:

```bash
cd E:/work/pessoal/aluguel-manager/pages
sed -i "s|__DIR__ . '/includes/|__DIR__ . '/../includes/|g" imoveis.php inquilinos.php contratos.php pagamentos.php chat.php perfil.php
```

- [ ] **Step 3: Corrigir redirects dentro das páginas movidas**

Os redirects em `pages/imoveis.php` chamam `redirect('imoveis.php')` — isso funciona porque redirect é relativo ao diretório atual. Verifique cada arquivo e confirme que todos os `redirect()` usam apenas o nome do arquivo (sem barra), por exemplo:

`redirect('imoveis.php')` → correto (permanece igual)
`redirect('contratos.php')` → correto

- [ ] **Step 4: Atualizar o sidebar em `includes/layout.php`**

Substitua o bloco `<nav>` inteiro:

```php
    <nav>
        <a href="<?= $base ?>/index.php" class="<?= $page === 'index.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="<?= $base ?>/pages/imoveis.php" class="<?= $page === 'imoveis.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Imóveis
        </a>
        <a href="<?= $base ?>/pages/inquilinos.php" class="<?= $page === 'inquilinos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Inquilinos
        </a>
        <a href="<?= $base ?>/pages/contratos.php" class="<?= $page === 'contratos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Contratos
        </a>
        <a href="<?= $base ?>/pages/pagamentos.php" class="<?= $page === 'pagamentos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            Pagamentos
        </a>
        <a href="<?= $base ?>/pages/chat.php" class="<?= $page === 'chat.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Chat IA
        </a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <?php
        $db = getDB();
        $adminCheck = $db->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
        $adminCheck->execute([$_SESSION['user_id']]);
        $isAdminNav = (bool)($adminCheck->fetchColumn());
        ?>
        <?php if ($isAdminNav): ?>
        <a href="<?= $base ?>/pages/usuarios.php" class="<?= $page === 'usuarios.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Usuários
        </a>
        <?php endif ?>
        <a href="<?= $base ?>/pages/grupos.php" class="<?= $page === 'grupos.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6c0-1.1.9-2 2-2h3l2 3H1V6z"/><path d="M23 6c0-1.1-.9-2-2-2h-3l-2 3h7V6z"/><path d="M3 17h18l1-9H2l1 9z"/></svg>
            Grupos
        </a>
        <a href="<?= $base ?>/pages/perfil.php" class="<?= $page === 'perfil.php' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Meu Perfil
        </a>
        <a href="<?= $base ?>/logout.php" style="margin-top:4px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sair
        </a>
        <?php endif ?>
    </nav>
```

- [ ] **Step 5: Testar navegação**

Acesse `http://localhost:8000`, faça login, e clique em cada item do menu.
Expected: todas as páginas carregam em `/pages/nome.php` sem erros 500.

- [ ] **Step 6: Commit**

```bash
git add pages/ includes/layout.php
git commit -m "refactor: move paginas autenticadas para pages/"
```

---

## Task 3: `includes/visibilidade.php` e `includes/auth.php`

**Files:**
- Create: `includes/visibilidade.php`
- Modify: `includes/auth.php`

- [ ] **Step 1: Criar `includes/visibilidade.php`**

```php
<?php
/**
 * Retorna array de IDs de imóveis que o usuário pode ver.
 */
function imoveisVisiveis(int $userId): array {
    $db = getDB();

    // Próprios
    $st = $db->prepare("SELECT id FROM imoveis WHERE usuario_id = ?");
    $st->execute([$userId]);
    $ids = array_column($st->fetchAll(), 'id');

    // Via grupo — acesso 'todos'
    $st = $db->prepare("
        SELECT DISTINCT i.id
        FROM imoveis i
        JOIN grupos g ON g.dono_id = i.usuario_id
        JOIN grupo_membros gm ON gm.grupo_id = g.id
        WHERE gm.usuario_id = ? AND gm.acesso_imoveis = 'todos'
    ");
    $st->execute([$userId]);
    $ids = array_unique(array_merge($ids, array_column($st->fetchAll(), 'id')));

    // Via grupo — acesso 'selecionados'
    $st = $db->prepare("
        SELECT DISTINCT gmi.imovel_id
        FROM grupo_membro_imoveis gmi
        JOIN grupo_membros gm ON gm.id = gmi.grupo_membro_id
        WHERE gm.usuario_id = ?
    ");
    $st->execute([$userId]);
    $ids = array_unique(array_merge($ids, array_column($st->fetchAll(), 'imovel_id')));

    return array_map('intval', $ids);
}

/**
 * Retorna as permissões do usuário sobre um imóvel específico.
 * Donos têm permissão total. Membros têm o que o dono configurou.
 */
function permissoesImovel(int $userId, int $imovelId): array {
    $db = getDB();
    $st = $db->prepare("SELECT usuario_id FROM imoveis WHERE id = ?");
    $st->execute([$imovelId]);
    $row = $st->fetch();
    if ($row && (int)$row['usuario_id'] === $userId) {
        return ['ver_valor'=>1,'ver_pagamento'=>1,'ver_ocupacao'=>1,'pode_escrever'=>1];
    }
    $st = $db->prepare("
        SELECT gm.ver_valor, gm.ver_pagamento, gm.ver_ocupacao, gm.pode_escrever
        FROM grupo_membros gm
        JOIN grupos g ON g.id = gm.grupo_id
        JOIN imoveis i ON i.usuario_id = g.dono_id
        WHERE gm.usuario_id = ? AND i.id = ?
        LIMIT 1
    ");
    $st->execute([$userId, $imovelId]);
    $p = $st->fetch();
    return $p ?: ['ver_valor'=>0,'ver_pagamento'=>0,'ver_ocupacao'=>0,'pode_escrever'=>0];
}

/**
 * Retorna placeholder se o usuário não pode ver determinado campo.
 */
function valorOuMascara(float $valor, bool $podeVer): string {
    return $podeVer ? moeda($valor) : '—';
}
```

- [ ] **Step 2: Adicionar `requireAdmin()` e `isAdmin()` em `includes/auth.php`**

Adicione ao final do arquivo `includes/auth.php`:

```php
function isAdmin(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $db = getDB();
    $st = $db->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    return (bool)$st->fetchColumn();
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        flash('Acesso restrito a administradores.', 'error');
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        header('Location: ' . $base . '/../index.php');
        exit;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/visibilidade.php includes/auth.php
git commit -m "feat: visibilidade.php e requireAdmin() em auth.php"
```

---

## Task 4: Recuperação de senha

**Files:**
- Create: `pages/recuperar-senha.php`
- Create: `pages/redefinir-senha.php`
- Modify: `login.php` — adicionar link "Esqueceu a senha?"

- [ ] **Step 1: Criar `pages/recuperar-senha.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if (!empty($_SESSION['user_id'])) { header('Location: ' . $base . '/../index.php'); exit; }

$msg   = '';
$tipo  = 'success';
$devLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $db    = getDB();
    $st    = $db->prepare("SELECT id, nome FROM usuarios WHERE email = ? AND ativo = 1");
    $st->execute([$email]);
    $user  = $st->fetch();

    if ($user) {
        $token     = bin2hex(random_bytes(32));
        $expires   = date('Y-m-d H:i:s', time() + 3600);
        $db->prepare("INSERT INTO recuperacao_tokens (usuario_id, token, expires_at) VALUES (?,?,?)")
           ->execute([$user['id'], $token, $expires]);

        $link    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                 . $base . '/redefinir-senha.php?token=' . $token;
        $subject = 'Recuperação de senha — Aluguel Manager';
        $body    = "Olá, {$user['nome']}!\n\nClique no link abaixo para redefinir sua senha (válido por 1 hora):\n\n{$link}\n\nSe não solicitou, ignore este email.";
        $headers = 'From: noreply@aluguelmanager.local';

        if (@mail($email, $subject, $body, $headers)) {
            $msg = 'Email enviado! Verifique sua caixa de entrada.';
        } else {
            // Fallback dev: exibe o link na tela
            $msg     = '[DEV] mail() não disponível localmente. Use o link abaixo:';
            $tipo    = 'error';
            $devLink = $link;
        }
    } else {
        // Não revela se email existe
        $msg = 'Se esse email estiver cadastrado, você receberá as instruções em breve.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar Senha — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= $base ?>/../assets/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
        .login-box { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:40px 36px; width:100%; max-width:380px; box-shadow:var(--shadow-md); }
        .login-logo { display:flex; align-items:center; gap:10px; font-size:1.1rem; font-weight:700; color:var(--text); margin-bottom:28px; }
        .login-logo svg { color:var(--primary); }
        .login-box .btn-primary { width:100%; justify-content:center; padding:10px; margin-top:8px; }
        .dev-link { word-break:break-all; background:#f1f5f9; padding:10px; border-radius:6px; font-size:.8rem; margin-top:10px; }
        .back-link { display:block; text-align:center; margin-top:16px; font-size:.875rem; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Aluguel Manager
    </div>
    <h1 style="font-size:1.2rem;font-weight:700;margin-bottom:6px">Recuperar Senha</h1>
    <p style="font-size:.875rem;color:var(--text-2);margin-bottom:20px">Informe seu email e enviaremos um link de recuperação.</p>

    <?php if ($msg): ?>
        <div class="flash <?= $tipo === 'error' ? 'flash-error' : 'flash-success' ?>"><?= h($msg) ?></div>
        <?php if ($devLink): ?>
            <div class="dev-link"><a href="<?= h($devLink) ?>"><?= h($devLink) ?></a></div>
        <?php endif ?>
    <?php endif ?>

    <?php if (!$msg): ?>
    <form method="POST">
        <div class="field" style="margin-bottom:16px">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
        </div>
        <button type="submit" class="btn btn-primary">Enviar Link</button>
    </form>
    <?php endif ?>

    <a href="<?= $base ?>/../login.php" class="back-link">← Voltar ao login</a>
</div>
</body>
</html>
```

- [ ] **Step 2: Criar `pages/redefinir-senha.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$token = trim($_GET['token'] ?? '');
$erro  = '';
$ok    = false;

$db = getDB();
$st = $db->prepare("SELECT rt.id, rt.usuario_id FROM recuperacao_tokens rt
    WHERE rt.token = ? AND rt.usado = 0 AND rt.expires_at > NOW()");
$st->execute([$token]);
$tokenRow = $st->fetch();

if (!$token || !$tokenRow) {
    $erro = 'Link inválido ou expirado. Solicite um novo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    $nova    = $_POST['senha_nova']    ?? '';
    $confirm = $_POST['senha_confirm'] ?? '';
    if (strlen($nova) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($nova !== $confirm) {
        $erro = 'As senhas não coincidem.';
    } else {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $db->prepare("UPDATE usuarios SET senha=? WHERE id=?")->execute([$hash, $tokenRow['usuario_id']]);
        $db->prepare("UPDATE recuperacao_tokens SET usado=1 WHERE id=?")->execute([$tokenRow['id']]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir Senha — Aluguel Manager</title>
    <link rel="stylesheet" href="<?= $base ?>/../assets/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
        .login-box { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:40px 36px; width:100%; max-width:380px; box-shadow:var(--shadow-md); }
        .login-logo { display:flex; align-items:center; gap:10px; font-size:1.1rem; font-weight:700; color:var(--text); margin-bottom:28px; }
        .login-logo svg { color:var(--primary); }
        .login-box .btn-primary { width:100%; justify-content:center; padding:10px; margin-top:8px; }
        .back-link { display:block; text-align:center; margin-top:16px; font-size:.875rem; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Aluguel Manager
    </div>

    <?php if ($ok): ?>
        <div class="flash flash-success">Senha redefinida com sucesso!</div>
        <a href="<?= $base ?>/../login.php" class="btn btn-primary" style="display:flex;justify-content:center;margin-top:16px">Ir para o Login</a>
    <?php elseif ($erro && !$tokenRow): ?>
        <div class="flash flash-error"><?= h($erro) ?></div>
        <a href="<?= $base ?>/recuperar-senha.php" class="back-link">Solicitar novo link</a>
    <?php else: ?>
        <h1 style="font-size:1.2rem;font-weight:700;margin-bottom:20px">Nova Senha</h1>
        <?php if ($erro): ?><div class="flash flash-error"><?= h($erro) ?></div><?php endif ?>
        <form method="POST">
            <div class="field" style="margin-bottom:16px">
                <label for="senha_nova">Nova Senha</label>
                <input type="password" id="senha_nova" name="senha_nova" required minlength="6" autofocus>
            </div>
            <div class="field" style="margin-bottom:16px">
                <label for="senha_confirm">Confirmar Senha</label>
                <input type="password" id="senha_confirm" name="senha_confirm" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary">Salvar Nova Senha</button>
        </form>
    <?php endif ?>
</div>
</body>
</html>
```

- [ ] **Step 3: Adicionar link em `login.php`**

Após o `<button type="submit"...>`, adicione:

```html
<a href="pages/recuperar-senha.php" style="display:block;text-align:center;margin-top:14px;font-size:.875rem;">Esqueceu a senha?</a>
```

- [ ] **Step 4: Testar fluxo**

1. Acesse `http://localhost:8000/login.php` → clique "Esqueceu a senha?"
2. Digite um email cadastrado → clique Enviar
3. Expected: como estamos em localhost, aparece `[DEV]` com o link na tela
4. Clique no link → preencha nova senha → clique Salvar
5. Expected: redireciona para login com mensagem de sucesso
6. Faça login com a nova senha → Expected: acesso liberado

- [ ] **Step 5: Commit**

```bash
git add pages/recuperar-senha.php pages/redefinir-senha.php login.php
git commit -m "feat: recuperacao de senha com token + fallback dev"
```

---

## Task 5: Gerenciamento de usuários (admin)

**Files:**
- Create: `pages/usuarios.php`

- [ ] **Step 1: Criar `pages/usuarios.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();
$db  = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'save') {
    $nome    = trim($_POST['nome'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $isAdm   = isset($_POST['is_admin']) ? 1 : 0;
    $editId  = (int)($_POST['id'] ?? 0);
    $senha   = $_POST['senha'] ?? '';

    if (!$nome || !$email) { flash('Nome e email são obrigatórios.','error'); redirect('usuarios.php'); }

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
        if (!$senha) { flash('Senha obrigatória para novo usuário.','error'); redirect('usuarios.php'); }
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
```

- [ ] **Step 2: Testar**

1. Faça login como admin → clique "Usuários" no menu
2. Crie um novo usuário com email e senha
3. Expected: usuário aparece na lista
4. Clique Editar → mude o nome → Salvar → Expected: nome atualizado
5. Clique Desativar → Expected: status muda para Inativo

- [ ] **Step 3: Commit**

```bash
git add pages/usuarios.php
git commit -m "feat: gerenciamento de usuarios para admin"
```

---

## Task 6: Gerenciamento de grupos

**Files:**
- Create: `pages/grupos.php`

- [ ] **Step 1: Criar `pages/grupos.php`**

```php
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
    if (!$nome) { flash('Nome do grupo obrigatório.','error'); redirect('grupos.php'); }
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
    if (!$st->fetch()) { flash('Grupo não encontrado.','error'); redirect('grupos.php'); }
    $st = $db->prepare("SELECT id FROM usuarios WHERE email=? AND ativo=1");
    $st->execute([$email]);
    $membro = $st->fetch();
    if (!$membro) { flash('Usuário não encontrado.','error'); redirect('grupos.php#grupo-'.$grupoId); }
    if ((int)$membro['id'] === $userId) { flash('Você não pode se adicionar ao próprio grupo.','error'); redirect('grupos.php#grupo-'.$grupoId); }
    try {
        $db->prepare("INSERT INTO grupo_membros (grupo_id,usuario_id) VALUES (?,?)")->execute([$grupoId,$membro['id']]);
        flash('Membro adicionado.');
    } catch (\PDOException $e) {
        flash('Usuário já é membro deste grupo.','error');
    }
    redirect('grupos.php#grupo-'.$grupoId);
}

// ── Salvar permissões do membro ────────────────────────────────
if ($action === 'salvar_permissoes') {
    $membroId = (int)($_POST['membro_id'] ?? 0);
    // Verifica que o membro é de um grupo do usuário logado
    $st = $db->prepare("
        SELECT gm.id FROM grupo_membros gm
        JOIN grupos g ON g.id = gm.grupo_id
        WHERE gm.id=? AND g.dono_id=?
    ");
    $st->execute([$membroId,$userId]);
    if (!$st->fetch()) { flash('Acesso negado.','error'); redirect('grupos.php'); }

    $acesso       = $_POST['acesso_imoveis'] === 'selecionados' ? 'selecionados' : 'todos';
    $verValor     = isset($_POST['ver_valor'])     ? 1 : 0;
    $verPagamento = isset($_POST['ver_pagamento']) ? 1 : 0;
    $verOcupacao  = isset($_POST['ver_ocupacao'])  ? 1 : 0;
    $podeEscrever = isset($_POST['pode_escrever']) ? 1 : 0;

    $db->prepare("UPDATE grupo_membros SET acesso_imoveis=?,ver_valor=?,ver_pagamento=?,ver_ocupacao=?,pode_escrever=? WHERE id=?")
       ->execute([$acesso,$verValor,$verPagamento,$verOcupacao,$podeEscrever,$membroId]);

    // Imóveis selecionados
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
$grupos = $db->prepare("SELECT * FROM grupos WHERE dono_id=? ORDER BY nome");
$grupos->execute([$userId]);
$grupos = $grupos->fetchAll();

$meusImoveis = $db->prepare("SELECT id,endereco FROM imoveis WHERE usuario_id=? ORDER BY endereco");
$meusImoveis->execute([$userId]);
$meusImoveis = $meusImoveis->fetchAll();

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
        $editandoMembro['imoveis_selecionados'] = array_column($stSel->fetchAll(),'imovel_id');
    }
}

layoutHead('Grupos');
renderFlash();
?>
<div class="page-title">
    <h1>Grupos</h1>
</div>

<?php if ($editandoMembro): ?>
<!-- Formulário de permissões -->
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

<!-- Criar novo grupo -->
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
$membros = $db->prepare("SELECT gm.*, u.nome AS membro_nome, u.email AS membro_email FROM grupo_membros gm JOIN usuarios u ON u.id=gm.usuario_id WHERE gm.grupo_id=?");
$membros->execute([$grupo['id']]);
$membros = $membros->fetchAll();
?>
<div class="table-wrap" style="margin-bottom:20px" id="grupo-<?= $grupo['id'] ?>">
    <div class="table-header">
        <h2><?= h($grupo['nome']) ?></h2>
        <a href="grupos.php?action=excluir_grupo&id=<?= $grupo['id'] ?>" class="btn btn-danger btn-sm"
           data-confirm="Excluir grupo '<?= h($grupo['nome']) ?>'? Os membros perderão o acesso.">Excluir</a>
    </div>

    <!-- Adicionar membro -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-end">
        <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex:1">
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
```

- [ ] **Step 2: Testar**

1. Acesse `http://localhost:8000/pages/grupos.php`
2. Crie um grupo "Família"
3. Adicione um membro pelo email de outro usuário existente
4. Clique Permissões → configure acesso selecionado + checkboxes
5. Expected: ao salvar, a tabela mostra os checkmarks corretos

- [ ] **Step 3: Commit**

```bash
git add pages/grupos.php
git commit -m "feat: gerenciamento de grupos com matriz de permissoes"
```

---

## Task 7: Visibilidade em `pages/imoveis.php`

**Files:**
- Modify: `pages/imoveis.php`

- [ ] **Step 1: Adicionar `usuario_id` ao INSERT e filtrar lista por visibilidade**

Substitua o conteúdo de `pages/imoveis.php`:

```php
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
        // Verifica ownership
        $perm = permissoesImovel($userId, $editId);
        if (!$perm['pode_escrever']) { flash('Sem permissão para editar este imóvel.','error'); redirect('imoveis.php'); }
        $db->prepare("UPDATE imoveis SET endereco=?,tipo=?,valor_aluguel=?,status=? WHERE id=?")
           ->execute([$endereco,$tipo,$valor,$status,$editId]);
        flash('Imóvel atualizado.');
    } else {
        $db->prepare("INSERT INTO imoveis (usuario_id,endereco,tipo,valor_aluguel,status) VALUES (?,?,?,?,?)")
           ->execute([$userId,$endereco,$tipo,$valor,$status]);
        flash('Imóvel cadastrado.');
    }
    redirect('imoveis.php');
}

if ($action === 'delete' && $id) {
    $perm = permissoesImovel($userId, $id);
    if (!$perm['pode_escrever']) { flash('Sem permissão.','error'); redirect('imoveis.php'); }
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
```

- [ ] **Step 2: Commit**

```bash
git add pages/imoveis.php
git commit -m "feat: imoveis.php com ownership, visibilidade e permissoes"
```

---

## Task 8: Visibilidade em contratos e pagamentos

**Files:**
- Modify: `pages/contratos.php`
- Modify: `pages/pagamentos.php`

- [ ] **Step 1: Adicionar filtro por imóveis visíveis em `pages/contratos.php`**

Logo após o `requireLogin()`, adicione:

```php
require_once __DIR__ . '/../includes/visibilidade.php';
$userId  = (int)$_SESSION['user_id'];
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';
```

Substitua a query que lista contratos para usar `$inClause`:

```php
$contratos = $db->query("
    SELECT c.*, i.endereco, q.nome AS inquilino_nome
    FROM contratos c
    JOIN imoveis i ON i.id = c.imovel_id
    JOIN inquilinos q ON q.id = c.inquilino_id
    WHERE c.imovel_id IN ({$inClause})
    ORDER BY c.ativo DESC, i.endereco
")->fetchAll();
```

- [ ] **Step 2: Adicionar filtro por imóveis visíveis em `pages/pagamentos.php`**

Mesmo padrão — adicione `require_once visibilidade.php`, compute `$inClause` e filtre a query de pagamentos:

```php
require_once __DIR__ . '/../includes/visibilidade.php';
$userId   = (int)$_SESSION['user_id'];
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';
```

Na query de pagamentos:
```php
// Adicione ao WHERE: AND c.imovel_id IN ({$inClause})
```

Para o botão de registrar pagamento, verifique `permissoesImovel()`:
```php
$perm = permissoesImovel($userId, (int)$contrato['imovel_id']);
// Só exibe botão "Registrar" se $perm['pode_escrever'] === 1
```

- [ ] **Step 3: Commit**

```bash
git add pages/contratos.php pages/pagamentos.php
git commit -m "feat: contratos e pagamentos filtrados por visibilidade"
```

---

## Task 9: Excel export via chat IA

**Files:**
- Create: `api/export.php`
- Modify: `api/chat.php`
- Modify: `includes/gemini.php`
- Modify: `assets/app.js`

- [ ] **Step 1: Criar `api/export.php`**

```php
<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/visibilidade.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }

$userId   = (int)$_SESSION['user_id'];
$tipo     = $_GET['tipo']   ?? '';
$filtro   = $_GET['filtro'] ?? '';
$mes      = $_GET['mes']    ?? '';

$db       = getDB();
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';

$tipos_validos = ['pagamentos','imoveis','inquilinos','contratos'];
if (!in_array($tipo, $tipos_validos, true)) { http_response_code(400); echo 'Tipo inválido'; exit; }

switch ($tipo) {
    case 'pagamentos':
        $where = "c.imovel_id IN ({$inClause})";
        if ($filtro) $where .= " AND p.status = " . $db->quote($filtro);
        if ($mes)    $where .= " AND p.mes_referencia = " . $db->quote($mes);
        $rows = $db->query("
            SELECT q.nome AS Inquilino, i.endereco AS Imovel,
                   p.mes_referencia AS Mes, p.status AS Status,
                   p.valor_pago AS Valor_Pago, p.data_pagamento AS Data_Pagamento
            FROM pagamentos p
            JOIN contratos c ON c.id = p.contrato_id
            JOIN inquilinos q ON q.id = c.inquilino_id
            JOIN imoveis i ON i.id = c.imovel_id
            WHERE {$where}
            ORDER BY p.mes_referencia DESC, q.nome
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'pagamentos' . ($filtro ? "-{$filtro}" : '') . ($mes ? "-{$mes}" : '') . '.xls';
        break;

    case 'imoveis':
        $rows = $db->query("
            SELECT endereco AS Endereco, tipo AS Tipo,
                   valor_aluguel AS Valor_Aluguel, status AS Status
            FROM imoveis WHERE id IN ({$inClause}) ORDER BY endereco
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'imoveis.xls';
        break;

    case 'inquilinos':
        $rows = $db->query("
            SELECT nome AS Nome, cpf AS CPF, telefone AS Telefone, email AS Email
            FROM inquilinos ORDER BY nome
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'inquilinos.xls';
        break;

    case 'contratos':
        $rows = $db->query("
            SELECT i.endereco AS Imovel, q.nome AS Inquilino,
                   c.data_inicio AS Inicio, c.data_fim AS Fim,
                   c.valor_mensal AS Valor_Mensal, c.dia_vencimento AS Dia_Venc,
                   CASE c.ativo WHEN 1 THEN 'Ativo' ELSE 'Encerrado' END AS Status
            FROM contratos c
            JOIN imoveis i ON i.id = c.imovel_id
            JOIN inquilinos q ON q.id = c.inquilino_id
            WHERE c.imovel_id IN ({$inClause})
            ORDER BY c.ativo DESC, i.endereco
        ")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'contratos.xls';
        break;
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

if (empty($rows)) { echo '<table><tr><td>Nenhum dado encontrado.</td></tr></table>'; exit; }

echo '<table border="1">';
echo '<tr>';
foreach (array_keys($rows[0]) as $col) {
    echo '<th>' . htmlspecialchars(str_replace('_',' ',$col)) . '</th>';
}
echo '</tr>';
foreach ($rows as $row) {
    echo '<tr>';
    foreach ($row as $val) echo '<td>' . htmlspecialchars((string)($val ?? '')) . '</td>';
    echo '</tr>';
}
echo '</table>';
```

- [ ] **Step 2: Atualizar prompt em `includes/gemini.php`**

Substitua a linha da variável `$prompt`:

```php
    $prompt = "Você é um assistente de gestão de aluguéis. Responda em português, de forma clara e direta, baseado apenas nos dados abaixo. Se não souber, diga que não tem a informação.\n\nQuando o usuário pedir para gerar, exportar ou baixar uma planilha/Excel/relatório, responda APENAS com uma tag no formato:\n[EXPORTAR:tipo:filtro]\nOnde:\n- tipo: pagamentos | imoveis | inquilinos | contratos\n- filtro (opcional): atrasado | pendente | pago | YYYY-MM\nExemplos:\n'quem está devendo e gera excel' → [EXPORTAR:pagamentos:atrasado]\n'exportar pagamentos de maio 2026' → [EXPORTAR:pagamentos:2026-05]\n'gerar excel de imóveis' → [EXPORTAR:imoveis:]\nNão adicione mais nada na resposta quando retornar uma tag [EXPORTAR].\n\nDADOS DO SISTEMA:\n{$contexto}\n\nPERGUNTA: {$pergunta}";
```

- [ ] **Step 3: Atualizar `api/chat.php` para detectar [EXPORTAR]**

Substitua o bloco `try { ... }`:

```php
try {
    $resposta = geminiChat($pergunta);

    if (preg_match('/\[EXPORTAR:(\w+):([^\]]*)\]/', $resposta, $m)) {
        $tipo   = $m[1];
        $filtro = trim($m[2]);
        $url    = 'api/export.php?tipo=' . urlencode($tipo);
        if (preg_match('/^\d{4}-\d{2}$/', $filtro)) {
            $url .= '&mes=' . urlencode($filtro);
        } elseif ($filtro) {
            $url .= '&filtro=' . urlencode($filtro);
        }
        $labels = ['pagamentos'=>'Pagamentos','imoveis'=>'Imóveis','inquilinos'=>'Inquilinos','contratos'=>'Contratos'];
        $label  = ($labels[$tipo] ?? $tipo) . ($filtro ? " ({$filtro})" : '');
        echo json_encode([
            'resposta' => 'Planilha pronta!',
            'download' => ['url' => $url, 'label' => "Baixar Excel — {$label}"]
        ]);
        exit;
    }

    echo json_encode(['resposta' => $resposta]);
} catch (Throwable $e) {
    echo json_encode(['resposta' => 'Erro interno: ' . $e->getMessage()]);
}
```

- [ ] **Step 4: Atualizar `assets/app.js` para renderizar botão de download**

Substitua o bloco do chat (função IIFE) em `assets/app.js`:

```js
(function() {
    const form = document.getElementById('chat-form');
    if (!form) return;

    const input    = form.querySelector('#chat-input');
    const messages = document.getElementById('chat-messages');

    function appendMsg(text, type) {
        const div = document.createElement('div');
        div.className = 'msg msg-' + type;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    function appendDownload(texto, download) {
        const div = document.createElement('div');
        div.className = 'msg msg-ai';
        div.innerHTML = texto + '<br><a class="btn-download" href="' + download.url + '" download>' + download.label + '</a>';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const q = input.value.trim();
        if (!q) return;
        input.value = '';
        appendMsg(q, 'user');
        const typing = appendMsg('Digitando...', 'ai msg-typing');

        try {
            const res = await fetch('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'pergunta=' + encodeURIComponent(q)
            });
            const data = await res.json();
            typing.remove();
            if (data.download) {
                appendDownload(data.resposta || 'Pronto!', data.download);
            } else {
                appendMsg(data.resposta || 'Sem resposta.', 'ai');
            }
        } catch (err) {
            typing.remove();
            appendMsg('Erro ao conectar com a IA.', 'ai');
        }
    });
})();
```

- [ ] **Step 5: Testar export**

1. Acesse o chat, envie: `"quem está com pagamento atrasado? gera um excel"`
2. Expected: botão "Baixar Excel — Pagamentos (atrasado)" aparece no chat
3. Clique no botão → Expected: download de `pagamentos-atrasado.xls`
4. Abra o arquivo no Excel/LibreOffice → Expected: tabela com colunas Inquilino, Imóvel, Mês, Status, Valor Pago, Data

- [ ] **Step 6: Commit**

```bash
git add api/export.php api/chat.php includes/gemini.php assets/app.js
git commit -m "feat: excel export via chat IA com filtros e visibilidade"
```

---

## Task 10: Atualizar usuário padrão no seed e ajustes finais

**Files:**
- Modify: `index.php` — dashboard filtra por imóveis visíveis
- Modify: `pages/chat.php` — confirmar fetch aponta para caminho correto

- [ ] **Step 1: Corrigir fetch no `pages/chat.php`**

O chat em `pages/chat.php` faz `fetch('api/chat.php', ...)`. Como a página está em `pages/`, o caminho relativo aponta para `pages/api/chat.php` que não existe. Corrija para caminho absoluto.

Em `pages/chat.php`, o JS é carregado via `assets/app.js` que tem `fetch('api/chat.php', ...)`.

Adicione em `pages/chat.php`, antes de `layoutFoot()`:

```php
<script>
// Sobrescreve o caminho da API para páginas em subdiretórios
window.chatApiPath = '<?= rtrim(str_replace("\\", "/", dirname(dirname($_SERVER['SCRIPT_NAME']))), "/") ?>/api/chat.php';
</script>
```

Em `assets/app.js`, substitua `fetch('api/chat.php', ...)` por:

```js
const apiPath = window.chatApiPath || 'api/chat.php';
// ...
const res = await fetch(apiPath, {
```

- [ ] **Step 2: Filtrar dashboard por visibilidade**

Em `index.php`, após `require_once` dos includes, adicione:

```php
require_once __DIR__ . '/includes/visibilidade.php';
$userId   = (int)$_SESSION['user_id'];
$visiveis = imoveisVisiveis($userId);
$inClause = $visiveis ? implode(',', $visiveis) : '0';
```

Substitua as queries que usam `FROM imoveis` e `FROM pagamentos` para filtrar por `$inClause`.

- [ ] **Step 3: Commit final**

```bash
git add index.php pages/chat.php assets/app.js
git commit -m "feat: dashboard e chat filtrados por visibilidade do usuario"
```
