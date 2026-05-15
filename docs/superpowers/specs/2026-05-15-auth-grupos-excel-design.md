# Auth, Grupos, Excel Export — Design Spec

**Data:** 2026-05-15
**Status:** Aprovado

---

## Objetivo

Adicionar ao Aluguel Manager:
1. Recuperação de senha via email (PHP mail() + fallback dev)
2. Gerenciamento de usuários pelo admin
3. Sistema de grupos com matriz de permissões controlada pelo dono
4. Exportação Excel gerada pela IA via chat
5. Reorganização de arquivos (páginas em `pages/`)

---

## 1. Schema do Banco

### Tabelas novas

```sql
-- Tokens para recuperação de senha
CREATE TABLE recuperacao_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grupos de compartilhamento
CREATE TABLE grupos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    dono_id INT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dono_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Membros de um grupo + matriz de permissões
CREATE TABLE grupo_membros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grupo_id INT NOT NULL,
    usuario_id INT NOT NULL,
    acesso_imoveis ENUM('todos','selecionados') NOT NULL DEFAULT 'todos',
    ver_valor      TINYINT(1) NOT NULL DEFAULT 0,
    ver_pagamento  TINYINT(1) NOT NULL DEFAULT 0,
    ver_ocupacao   TINYINT(1) NOT NULL DEFAULT 1,
    pode_escrever  TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY (grupo_id, usuario_id),
    FOREIGN KEY (grupo_id)   REFERENCES grupos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Imóveis acessíveis por membro (quando acesso_imoveis = 'selecionados')
CREATE TABLE grupo_membro_imoveis (
    grupo_membro_id INT NOT NULL,
    imovel_id       INT NOT NULL,
    PRIMARY KEY (grupo_membro_id, imovel_id),
    FOREIGN KEY (grupo_membro_id) REFERENCES grupo_membros(id),
    FOREIGN KEY (imovel_id)       REFERENCES imoveis(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Alterações em tabelas existentes

```sql
-- Dono do imóvel
ALTER TABLE imoveis ADD COLUMN usuario_id INT AFTER id;
ALTER TABLE imoveis ADD FOREIGN KEY (usuario_id) REFERENCES usuarios(id);

-- Flag de admin
ALTER TABLE usuarios ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
```

### Migração de dados existentes
- Todos os imóveis sem `usuario_id` recebem o id do primeiro usuário `is_admin = 1`
- Usuário padrão `m.andrade.assis@gmail.com` / `123` criado com `is_admin = 1`

---

## 2. Regras de Visibilidade

Um usuário `U` pode ver o imóvel `I` se:
1. `I.usuario_id = U.id` (é o dono), OU
2. `U` é membro de um grupo cujo dono possui `I` E `acesso_imoveis = 'todos'`, OU
3. `U` é membro de um grupo cujo dono possui `I` E `acesso_imoveis = 'selecionados'` E existe registro em `grupo_membro_imoveis`

Permissões de campo (para membros de grupo, nunca para donos):
- `ver_valor = 0` → campo `valor_aluguel` exibido como `—`
- `ver_pagamento = 0` → aba/coluna de pagamentos oculta
- `ver_ocupacao = 0` → status do imóvel oculto
- `pode_escrever = 0` → botões de editar/salvar ocultados

Helper central: `includes/visibilidade.php` com função `imoveisVisiveis(int $userId): array`

---

## 3. Páginas e Rotas

### Estrutura de arquivos

```
/
├── index.php              ← dashboard
├── login.php
├── logout.php
├── config.php
│
├── pages/
│   ├── imoveis.php
│   ├── inquilinos.php
│   ├── contratos.php
│   ├── pagamentos.php
│   ├── chat.php
│   ├── perfil.php
│   ├── usuarios.php       ← admin only
│   ├── grupos.php
│   ├── recuperar-senha.php
│   └── redefinir-senha.php
│
├── api/
│   ├── chat.php
│   └── export.php
│
├── includes/
│   ├── db.php
│   ├── helpers.php
│   ├── layout.php
│   ├── auth.php
│   ├── gemini.php
│   └── visibilidade.php   ← novo
│
└── assets/
    ├── style.css
    └── app.js
```

### Controle de acesso por página

| Página | Acesso |
|---|---|
| `usuarios.php` | `is_admin = 1` |
| `grupos.php` | Qualquer usuário logado |
| `imoveis.php` criar/editar | Dono do imóvel |
| `pagamentos.php` registrar | Dono ou membro com `pode_escrever = 1` |
| Todas as demais | Qualquer logado |

---

## 4. Recuperação de Senha

**Fluxo:**
1. `recuperar-senha.php` — formulário com campo email
2. POST: busca usuário pelo email
   - Se não existe: exibe mensagem genérica (não revela existência)
   - Se existe: gera token `bin2hex(random_bytes(32))`, salva em `recuperacao_tokens` com `expires_at = NOW() + 1h`
3. Tenta `mail()` com link `redefinir-senha.php?token=xxx`
   - Se `mail()` retornar `false` (dev/localhost): exibe link na tela com aviso `[DEV] Link de recuperação:`
4. `redefinir-senha.php?token=xxx`:
   - Valida token (existe, não usado, não expirado)
   - Exibe formulário de nova senha (mínimo 6 chars)
   - POST: faz hash, atualiza `usuarios.senha`, marca token como usado

---

## 5. Gerenciamento de Usuários (Admin)

**`pages/usuarios.php`** — acessível apenas para `is_admin = 1`

Ações disponíveis:
- Listar todos os usuários
- Criar novo usuário (nome, email, senha temporária, is_admin)
- Editar usuário (nome, email, is_admin)
- Redefinir senha diretamente (admin seta nova senha sem token)
- Desativar usuário (soft delete: `ativo TINYINT` na tabela)

---

## 6. Grupos

**`pages/grupos.php`** — dono gerencia seus grupos

Fluxo de criação:
1. Dono cria grupo com nome
2. Dono adiciona membros (busca por email)
3. Para cada membro: define `acesso_imoveis` (todos/selecionados)
4. Se selecionados: lista de checkboxes dos imóveis do dono
5. Define permissões de campo: ver_valor, ver_pagamento, ver_ocupacao, pode_escrever

Interface: lista de grupos → ao expandir mostra membros + botões de editar permissão

---

## 7. Excel Export via Chat IA

**Detecção de intenção no `api/chat.php`:**
- Gemini instrução no prompt: quando pedido de exportação, retornar `[EXPORTAR:tipo:filtro]`
- Exemplo: "traga os que estão devendo e gere excel" → `[EXPORTAR:pagamentos:atrasado]`

**`api/export.php`** recebe `?tipo=pagamentos&status=atrasado` (ou outros filtros) e:
- Monta query respeitando visibilidade do usuário logado
- Gera arquivo `.xls` via HTML table + Content-Type correto
- Retorna download imediato

**Tipos suportados:** `pagamentos`, `imoveis`, `inquilinos`, `contratos`
**Filtros suportados:** `status` (atrasado/pendente/pago), `mes` (YYYY-MM)

**Frontend (`assets/app.js`):** quando API retorna `download`, renderiza botão de download no chat.

---

## 8. Usuário Padrão

Substituir o usuário `admin@admin.com` na migration pelo usuário real:
- **Email:** `m.andrade.assis@gmail.com`
- **Senha:** `123`
- **is_admin:** `1`
