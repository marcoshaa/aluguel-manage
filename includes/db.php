<?php
require_once __DIR__ . '/../config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        runMigrations($pdo);
    }
    return $pdo;
}

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

    // ── Storage: colunas em usuarios ─────────────────────────────
    $colsU = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'")->fetchAll(PDO::FETCH_COLUMN);
    $newColsU = [
        'storage_driver'              => "VARCHAR(10) NOT NULL DEFAULT 'b2'",
        'b2_key_id'                   => "VARCHAR(150)",
        'b2_app_key'                  => "VARCHAR(150)",
        'b2_bucket_id'                => "VARCHAR(100)",
        'b2_bucket_name'              => "VARCHAR(100)",
        'gdrive_service_account_json' => "TEXT",
        'gdrive_folder_id'            => "VARCHAR(100)",
    ];
    foreach ($newColsU as $col => $def) {
        if (!in_array($col, $colsU)) $pdo->exec("ALTER TABLE usuarios ADD COLUMN {$col} {$def}");
    }

    // ── Tabela arquivos ──────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS arquivos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type ENUM('imovel','inquilino','contrato') NOT NULL,
        entity_id INT NOT NULL,
        driver VARCHAR(10) NOT NULL,
        file_key VARCHAR(500) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        tamanho BIGINT NOT NULL DEFAULT 0,
        usuario_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
