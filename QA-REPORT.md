# QA Report — aluguel-manager
Data: 2026-05-15

---

## Check 1 — require_once paths em pages/

Todos os arquivos em `pages/` usam `__DIR__ . '/../includes/'`. Nenhum usa o path errado `__DIR__ . '/includes/'`.

| Arquivo | Status |
|---|---|
| pages/imoveis.php | PASS |
| pages/inquilinos.php | PASS |
| pages/contratos.php | PASS |
| pages/pagamentos.php | PASS |
| pages/chat.php | PASS |
| pages/perfil.php | PASS |
| pages/usuarios.php | PASS |
| pages/grupos.php | PASS |
| pages/recuperar-senha.php | PASS |
| pages/redefinir-senha.php | PASS |

**Resultado: PASS**

---

## Check 2 — visibilidade.php incluído onde necessário

| Local | Esperado | Status |
|---|---|---|
| pages/imoveis.php | `require_once __DIR__ . '/../includes/visibilidade.php'` | PASS |
| pages/contratos.php | `require_once __DIR__ . '/../includes/visibilidade.php'` | PASS |
| pages/pagamentos.php | `require_once __DIR__ . '/../includes/visibilidade.php'` | PASS |
| index.php | `require_once __DIR__ . '/includes/visibilidade.php'` | PASS |
| api/export.php | `require_once __DIR__ . '/../includes/visibilidade.php'` | PASS |

**ATENÇÃO — FAIL parcial:** `pages/inquilinos.php` **não inclui** `visibilidade.php`. Se a página usa `imoveisVisiveis()` ou `valorOuMascara()`, haverá erro fatal em runtime.

**Resultado: PASS (itens exigidos), porém FAIL em inquilinos.php (não listado no check mas potencialmente problemático)**

---

## Check 3 — Funções usadas existem

| Função | Arquivo | Status |
|---|---|---|
| `imoveisVisiveis()` | includes/visibilidade.php:5 | PASS |
| `permissoesImovel()` | includes/visibilidade.php:41 | PASS |
| `valorOuMascara()` | includes/visibilidade.php:65 | PASS |
| `requireAdmin()` | includes/auth.php:36 | PASS |
| `isAdmin()` | includes/auth.php:28 | PASS |

**Resultado: PASS**

---

## Check 4 — api/chat.php tem session_start()

`api/chat.php` linha 2: `session_start();` — presente.

**Resultado: PASS**

---

## Check 5 — window.chatApiPath em pages/chat.php

`pages/chat.php` linha 29:
```js
window.chatApiPath = '<?= rtrim(str_replace("\\", "/", dirname(dirname($_SERVER['SCRIPT_NAME']))), "/") ?>/api/chat.php';
```

**Resultado: PASS**

---

## Check 6 — api/export.php fecha sem erro de sintaxe

O arquivo termina com `echo '</table>';` sem `?>` solto ou mal formado. PHP não exige `?>` no final — ausência é correta e recomendada.

**Resultado: PASS**

---

## Check 7 — login.php tem link "Esqueceu a senha?"

`login.php` linha 77:
```html
<a href="pages/recuperar-senha.php" ...>Esqueceu a senha?</a>
```

**Resultado: PASS**

---

## Check 8 — includes/layout.php sidebar aponta para pages/

Todos os links de navegação usam `$base . '/pages/[arquivo].php'`:
- `/pages/imoveis.php` — PASS
- `/pages/inquilinos.php` — PASS
- `/pages/contratos.php` — PASS
- `/pages/pagamentos.php` — PASS
- `/pages/chat.php` — PASS
- `/pages/usuarios.php` — PASS
- `/pages/grupos.php` — PASS
- `/pages/perfil.php` — PASS

**Resultado: PASS**

---

## Resumo Executivo

| Check | Resultado |
|---|---|
| 1. require_once paths em pages/ | ✅ PASS |
| 2. visibilidade.php incluído onde necessário | ✅ PASS (itens do check) |
| 3. Funções definidas | ✅ PASS |
| 4. api/chat.php tem session_start() | ✅ PASS |
| 5. window.chatApiPath em pages/chat.php | ✅ PASS |
| 6. api/export.php sem erro de sintaxe | ✅ PASS |
| 7. login.php tem link recuperar-senha | ✅ PASS |
| 8. layout.php sidebar aponta para pages/ | ✅ PASS |

### Problema adicional encontrado (fora dos checks, mas crítico)

**FAIL — pages/inquilinos.php não inclui visibilidade.php**

O arquivo `pages/inquilinos.php` não tem `require_once __DIR__ . '/../includes/visibilidade.php'`. Se qualquer função de `visibilidade.php` for chamada nessa página, o PHP lançará um erro fatal (`Call to undefined function`). Recomenda-se adicionar o include na linha 6, antes de `layout.php`.
