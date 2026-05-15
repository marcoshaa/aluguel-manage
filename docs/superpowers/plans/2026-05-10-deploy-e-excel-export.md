# Deploy + Excel Export — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colocar o Aluguel Manager no ar no InfinityFree (MySQL) e adicionar exportação Excel via chat IA.

**Architecture:** Três fases independentes: (1) ambiente local com Laragon+MySQL, (2) exportação Excel via `api/export.php` com detecção de intenção no chat, (3) deploy no InfinityFree via FTP. A exportação usa HTML-table com Content-Type XLS — sem dependências externas, funciona em qualquer host PHP.

**Tech Stack:** PHP 7.4, MySQL, HTML/CSS/JS vanilla, Laragon (dev), InfinityFree (prod), FileZilla (FTP)

---

## Mapa de Arquivos

| Arquivo | Ação | O que muda |
|---|---|---|
| `config.php` | Modificar | Credenciais MySQL reais (local) |
| `includes/db.php` | Já migrado | DSN MySQL — sem mudança |
| `api/export.php` | Criar | Gera arquivo .xls por tipo |
| `api/chat.php` | Modificar | Detecta intenção de exportação |
| `includes/gemini.php` | Modificar | Prompt atualizado para retornar `[EXPORTAR:tipo]` |
| `assets/app.js` | Modificar | Renderiza botão de download quando IA retorna export |
| `assets/style.css` | Modificar | Estilo do botão de download no chat |

---

## Fase 1 — Ambiente Local com Laragon + MySQL

### Task 1: Instalar Laragon e criar banco MySQL

**Files:**
- Modify: `config.php`

- [ ] **Step 1: Baixar e instalar Laragon**

  Acesse laragon.org/download e baixe o Laragon Full (inclui PHP 8.2 e MySQL).
  Instale em `C:\laragon`. Inicie o Laragon e clique em **Start All**.

- [ ] **Step 2: Verificar PHP e MySQL ativos**

  No terminal do Laragon (ou Git Bash):
  ```bash
  php -v
  # Expected: PHP 8.x
  mysql --version
  # Expected: mysql  Ver 8.x
  ```

- [ ] **Step 3: Criar o banco de dados**

  No Laragon, clique em **Database** → abre HeidiSQL.
  Clique em **New** → conecte como root (sem senha por padrão).
  Execute:
  ```sql
  CREATE DATABASE aluguel_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

- [ ] **Step 4: Preencher config.php com as credenciais locais**

  Edite `config.php`:
  ```php
  <?php
  define('GEMINI_API_KEY', 'SUA_CHAVE_GEMINI_AQUI');

  define('DB_HOST', 'localhost');
  define('DB_NAME', 'aluguel_manager');
  define('DB_USER', 'root');
  define('DB_PASS', '');
  ```

- [ ] **Step 5: Iniciar o servidor PHP**

  Na pasta do projeto:
  ```bash
  php -S localhost:8000
  ```
  Acesse `http://localhost:8000` — o dashboard deve abrir sem erros.
  As tabelas serão criadas automaticamente pelo `runMigrations()` no primeiro acesso.

- [ ] **Step 6: Verificar tabelas criadas**

  No HeidiSQL, expanda `aluguel_manager` — devem existir:
  `imoveis`, `inquilinos`, `contratos`, `pagamentos`

- [ ] **Step 7: Commit**

  ```bash
  git add config.php
  git commit -m "chore: configura MySQL local via Laragon"
  ```

---

## Fase 2 — Exportação Excel via Chat IA

### Task 2: Criar `api/export.php`

**Files:**
- Create: `api/export.php`

- [ ] **Step 1: Criar o arquivo de exportação**

  Crie `api/export.php`:
  ```php
  <?php
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/helpers.php';

  $tipo = $_GET['tipo'] ?? '';
  $mes  = $_GET['mes']  ?? '';

  $tipos_validos = ['pagamentos', 'inquilinos', 'imoveis', 'contratos'];
  if (!in_array($tipo, $tipos_validos, true)) {
      http_response_code(400);
      echo 'Tipo inválido';
      exit;
  }

  $db = getDB();

  switch ($tipo) {
      case 'pagamentos':
          $where = $mes ? "WHERE p.mes_referencia = " . $db->quote($mes) : '';
          $rows  = $db->query("
              SELECT q.nome AS Inquilino, i.endereco AS Imovel,
                     p.mes_referencia AS Mes, p.status AS Status,
                     p.valor_pago AS Valor_Pago, p.data_pagamento AS Data_Pagamento
              FROM pagamentos p
              JOIN contratos c ON c.id = p.contrato_id
              JOIN inquilinos q ON q.id = c.inquilino_id
              JOIN imoveis i ON i.id = c.imovel_id
              {$where}
              ORDER BY p.mes_referencia DESC, q.nome
          ")->fetchAll(PDO::FETCH_ASSOC);
          $filename = 'pagamentos' . ($mes ? "-{$mes}" : '') . '.xls';
          break;

      case 'inquilinos':
          $rows = $db->query("
              SELECT nome AS Nome, cpf AS CPF, telefone AS Telefone,
                     email AS Email, data_nascimento AS Nascimento, criado_em AS Cadastro
              FROM inquilinos ORDER BY nome
          ")->fetchAll(PDO::FETCH_ASSOC);
          $filename = 'inquilinos.xls';
          break;

      case 'imoveis':
          $rows = $db->query("
              SELECT endereco AS Endereco, tipo AS Tipo,
                     valor_aluguel AS Valor_Aluguel, status AS Status, criado_em AS Cadastro
              FROM imoveis ORDER BY endereco
          ")->fetchAll(PDO::FETCH_ASSOC);
          $filename = 'imoveis.xls';
          break;

      case 'contratos':
          $rows = $db->query("
              SELECT i.endereco AS Imovel, q.nome AS Inquilino,
                     c.data_inicio AS Inicio, c.data_fim AS Fim,
                     c.valor_mensal AS Valor_Mensal, c.dia_vencimento AS Dia_Venc,
                     c.indice_reajuste AS Reajuste,
                     CASE c.ativo WHEN 1 THEN 'Ativo' ELSE 'Encerrado' END AS Status
              FROM contratos c
              JOIN imoveis i ON i.id = c.imovel_id
              JOIN inquilinos q ON q.id = c.inquilino_id
              ORDER BY c.ativo DESC, i.endereco
          ")->fetchAll(PDO::FETCH_ASSOC);
          $filename = 'contratos.xls';
          break;
  }

  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-cache, no-store');

  if (empty($rows)) {
      echo '<table><tr><td>Nenhum dado encontrado.</td></tr></table>';
      exit;
  }

  echo '<table border="1">';
  // Cabeçalho
  echo '<tr>';
  foreach (array_keys($rows[0]) as $col) {
      echo '<th>' . htmlspecialchars(str_replace('_', ' ', $col)) . '</th>';
  }
  echo '</tr>';
  // Dados
  foreach ($rows as $row) {
      echo '<tr>';
      foreach ($row as $val) {
          echo '<td>' . htmlspecialchars((string)($val ?? '')) . '</td>';
      }
      echo '</tr>';
  }
  echo '</table>';
  ```

- [ ] **Step 2: Testar no navegador**

  Com o servidor rodando:
  ```
  http://localhost:8000/api/export.php?tipo=pagamentos
  ```
  Expected: download de `pagamentos.xls` que abre no Excel/LibreOffice com colunas corretas.

  Testar também:
  ```
  http://localhost:8000/api/export.php?tipo=inquilinos
  http://localhost:8000/api/export.php?tipo=imoveis
  http://localhost:8000/api/export.php?tipo=contratos
  http://localhost:8000/api/export.php?tipo=pagamentos&mes=2026-05
  ```

- [ ] **Step 3: Commit**

  ```bash
  git add api/export.php
  git commit -m "feat: adiciona exportação Excel via api/export.php"
  ```

---

### Task 3: Integrar detecção de exportação no chat

**Files:**
- Modify: `includes/gemini.php` (linha 51 — variável `$prompt`)
- Modify: `api/chat.php`

- [ ] **Step 1: Atualizar o prompt do Gemini para retornar tag de exportação**

  Em `includes/gemini.php`, substitua a linha da variável `$prompt` (linha 51):
  ```php
  $prompt = "Você é um assistente de gestão de aluguéis. Responda em português, de forma clara e direta, baseado apenas nos dados abaixo. Se não souber, diga que não tem a informação.\n\nQuando o usuário pedir para gerar, exportar ou baixar uma planilha/Excel, responda APENAS com uma tag no formato:\n[EXPORTAR:tipo] ou [EXPORTAR:tipo:YYYY-MM]\nOnde tipo pode ser: pagamentos, inquilinos, imoveis, contratos.\nExemplo: 'exportar pagamentos de maio 2026' → [EXPORTAR:pagamentos:2026-05]\nExemplo: 'gerar excel de inquilinos' → [EXPORTAR:inquilinos]\nNão adicione mais nada na resposta quando retornar uma tag [EXPORTAR].\n\nDADOS DO SISTEMA:\n{$contexto}\n\nPERGUNTA: {$pergunta}";
  ```

- [ ] **Step 2: Atualizar `api/chat.php` para processar a tag de exportação**

  Substitua o conteúdo completo de `api/chat.php`:
  ```php
  <?php
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/helpers.php';
  require_once __DIR__ . '/../includes/gemini.php';

  header('Content-Type: application/json; charset=utf-8');

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      echo json_encode(['erro' => 'Método não permitido']);
      exit;
  }

  $pergunta = trim($_POST['pergunta'] ?? '');
  if (!$pergunta) {
      echo json_encode(['resposta' => 'Por favor, faça uma pergunta.']);
      exit;
  }

  try {
      $resposta = geminiChat($pergunta);

      // Detecta tag de exportação retornada pelo Gemini
      if (preg_match('/\[EXPORTAR:(\w+)(?::(\d{4}-\d{2}))?\]/', $resposta, $m)) {
          $tipo = $m[1];
          $mes  = $m[2] ?? '';
          $url  = 'api/export.php?tipo=' . urlencode($tipo) . ($mes ? '&mes=' . urlencode($mes) : '');
          $labels = ['pagamentos' => 'Pagamentos', 'inquilinos' => 'Inquilinos', 'imoveis' => 'Imóveis', 'contratos' => 'Contratos'];
          $label = $labels[$tipo] ?? $tipo;
          echo json_encode([
              'resposta' => 'Planilha pronta!',
              'download' => ['url' => $url, 'label' => "Baixar Excel — {$label}" . ($mes ? " ({$mes})" : '')]
          ]);
          exit;
      }

      echo json_encode(['resposta' => $resposta]);
  } catch (Throwable $e) {
      echo json_encode(['resposta' => 'Erro interno: ' . $e->getMessage()]);
  }
  ```

- [ ] **Step 3: Commit**

  ```bash
  git add includes/gemini.php api/chat.php
  git commit -m "feat: chat detecta pedido de exportação e retorna link de download"
  ```

---

### Task 4: Botão de download no frontend do chat

**Files:**
- Modify: `assets/app.js` (função `appendMsg` e handler do fetch)
- Modify: `assets/style.css`

- [ ] **Step 1: Atualizar `assets/app.js` para renderizar botão de download**

  Substitua o bloco do chat em `assets/app.js` (linhas 9–47):
  ```js
  // Chat IA
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
          div.innerHTML = texto + ' <a class="btn-download" href="' + download.url + '" download>' + download.label + '</a>';
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

- [ ] **Step 2: Adicionar estilo do botão de download em `assets/style.css`**

  Adicione ao final do arquivo `assets/style.css`:
  ```css
  .btn-download {
      display: inline-block;
      margin-top: 8px;
      padding: 6px 14px;
      background: #2563eb;
      color: #fff;
      border-radius: 6px;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
  }
  .btn-download:hover { background: #1d4ed8; }
  ```

- [ ] **Step 3: Testar o fluxo completo**

  Com o servidor rodando, abra `http://localhost:8000/chat.php` e envie:
  - `"gerar excel de inquilinos"` → Expected: botão de download aparece no chat
  - `"exportar pagamentos de maio 2026"` → Expected: botão de download para `pagamentos-2026-05.xls`
  - `"quem está atrasado?"` → Expected: resposta normal de texto

- [ ] **Step 4: Commit**

  ```bash
  git add assets/app.js assets/style.css
  git commit -m "feat: chat exibe botão de download quando IA retorna exportação Excel"
  ```

---

## Fase 3 — Deploy no InfinityFree

### Task 5: Criar conta e banco no InfinityFree

**Files:**
- Modify: `config.php` (credenciais de produção — NÃO commitar)

- [ ] **Step 1: Criar conta no InfinityFree**

  Acesse `infinityfree.com` → Sign Up → confirme o email.

- [ ] **Step 2: Criar um site**

  No painel → **Create Account** → escolha um subdomínio (ex: `aluguelmanager.epizy.com`) → Create.

- [ ] **Step 3: Criar banco MySQL**

  No painel do site → **MySQL Databases** → crie um banco.
  Anote: `host`, `nome do banco`, `usuário`, `senha`.
  > O host do InfinityFree é algo como `sql123.epizy.com` — copie exatamente.

- [ ] **Step 4: Criar as tabelas no banco de produção**

  No painel → **phpMyAdmin** → selecione o banco criado → aba **SQL** → execute:
  ```sql
  CREATE TABLE IF NOT EXISTS imoveis (
      id INT AUTO_INCREMENT PRIMARY KEY,
      endereco VARCHAR(255) NOT NULL,
      tipo ENUM('casa','apto','sala') NOT NULL,
      valor_aluguel DECIMAL(10,2) NOT NULL,
      status ENUM('disponivel','alugado') NOT NULL DEFAULT 'disponivel',
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS inquilinos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nome VARCHAR(150) NOT NULL,
      cpf VARCHAR(14) NOT NULL UNIQUE,
      telefone VARCHAR(20),
      email VARCHAR(150),
      data_nascimento DATE,
      criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS contratos (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS pagamentos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      contrato_id INT NOT NULL,
      mes_referencia VARCHAR(7) NOT NULL,
      valor_pago DECIMAL(10,2),
      data_pagamento DATE,
      status ENUM('pago','pendente','atrasado') NOT NULL DEFAULT 'pendente',
      FOREIGN KEY (contrato_id) REFERENCES contratos(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```

- [ ] **Step 5: Criar `config.php` de produção (local, sem commitar)**

  Edite `config.php` localmente com os dados do InfinityFree:
  ```php
  <?php
  define('GEMINI_API_KEY', 'SUA_CHAVE_GEMINI');

  define('DB_HOST', 'sql123.epizy.com');   // host real do InfinityFree
  define('DB_NAME', 'epiz_12345_aluguel'); // nome real do banco
  define('DB_USER', 'epiz_12345');         // usuário real
  define('DB_PASS', 'senha_real');
  ```
  > `config.php` está no `.gitignore` — não será commitado.

---

### Task 6: Upload via FTP com FileZilla

- [ ] **Step 1: Instalar FileZilla**

  Baixe em filezilla-project.org/download.php → FileZilla Client.

- [ ] **Step 2: Obter credenciais FTP do InfinityFree**

  No painel InfinityFree → **FTP Accounts** → anote: host, usuário, senha, porta (21).

- [ ] **Step 3: Conectar via FileZilla**

  Abra FileZilla → preencha os campos no topo:
  - Host: `ftpupload.net` (ou o informado pelo InfinityFree)
  - Usuário/Senha: do painel FTP
  - Porta: `21`
  - Clique **Quickconnect**

- [ ] **Step 4: Navegar até a pasta correta no servidor**

  No painel direito (servidor), acesse: `/htdocs/`

- [ ] **Step 5: Upload dos arquivos**

  No painel esquerdo (local), navegue até `E:\work\pessoal\aluguel-manager\`.
  Selecione e arraste para `/htdocs/` todos os arquivos e pastas, **exceto**:
  - `database/` (SQLite — não é mais usado)
  - `tests/`
  - `docs/`
  - `.git/`
  - `composer.json` / `composer.lock` / `vendor/` (não há dependências de produção)

  Lista do que **subir**:
  ```
  api/
  assets/
  includes/
  chat.php
  config.php        ← com credenciais de produção
  contratos.php
  imoveis.php
  index.php
  inquilinos.php
  pagamentos.php
  ```

- [ ] **Step 6: Verificar no navegador**

  Acesse `http://aluguelmanager.epizy.com` (ou seu subdomínio).
  Expected: dashboard carrega, tabelas já existem, sem erros 500.

- [ ] **Step 7: Testar o chat**

  No chat, envie "quais imóveis estão disponíveis?" → resposta da IA.
  Envie "gerar excel de inquilinos" → botão de download aparece.

---

## Checklist Final

- [ ] Laragon instalado e MySQL local funcionando
- [ ] Projeto roda em `localhost:8000` sem erros
- [ ] Chat responde perguntas via Gemini
- [ ] Chat gera botão de download ao pedir Excel
- [ ] Download gera arquivo `.xls` que abre no Excel/LibreOffice
- [ ] Conta InfinityFree criada e banco MySQL configurado
- [ ] Arquivos enviados via FTP
- [ ] Site acessível pelo subdomínio público
- [ ] Chat funciona em produção com Gemini API
