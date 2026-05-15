# Aluguel Manager — Feature Log

## ✅ Implementadas

### Fase 1 — Base
- Sistema de autenticação (login/logout com sessão PHP)
- Dashboard com resumo de imóveis, receita, atrasados
- CRUD de Imóveis
- CRUD de Inquilinos
- CRUD de Contratos
- Gestão de Pagamentos com geração automática mensal
- Chat IA via Gemini 2.5 Flash com contexto do banco
- Chave API Gemini por usuário (Meu Perfil)
- Layout responsivo Data-Dense (sidebar escura, Inter + Fira Code)
- Banco MySQL (FreeSQLDatabase.com) via PDO

### Fase 2 — Auth, Grupos & Excel (2026-05-15)
- Reorganização de páginas em pages/ (imoveis, inquilinos, contratos, pagamentos, chat, perfil)
- Migrations idempotentes via information_schema
- Usuário padrão m.andrade.assis@gmail.com / 123 (is_admin=1)
- Recuperação de senha via token (expires 1h) + fallback dev (link na tela)
- Gerenciamento de usuários (admin): criar, editar, desativar/reativar
- Sistema de grupos: dono cria grupos e adiciona membros por email
- Matriz de permissões por membro: acesso_imoveis (todos/selecionados), ver_valor, ver_pagamento, ver_ocupacao, pode_escrever
- Visibilidade filtrada: imoveisVisiveis(), permissoesImovel() em includes/visibilidade.php
- isAdmin() e requireAdmin() em includes/auth.php
- Dashboard, contratos e pagamentos filtrados por visibilidade
- Export Excel via Chat IA: Gemini retorna [EXPORTAR:tipo:filtro], api/export.php gera .xls
- Botão de download renderizado no chat pelo app.js
- window.chatApiPath para resolver fetch em subdiretório

## 🔜 Próximas Features (Backlog)

### Alta Prioridade
- [ ] Deploy em InfinityFree (hospedagem gratuita)
- [ ] Notificações de vencimento por email (cron ou trigger)
- [ ] Relatório mensal automático por email

### Média Prioridade
- [ ] Reajuste automático por índice (IGPM/IPCA via API)
- [ ] Upload de documentos do contrato (PDF)
- [ ] Histórico de reajustes aplicados
- [ ] Múltiplos imóveis por contrato

### Baixa Prioridade / Melhorias
- [ ] Dashboard com gráficos (Chart.js)
- [ ] Exportação PDF de contratos
- [ ] App mobile (PWA)
- [ ] Integração com gateway de pagamento (PIX)
- [ ] Notificações in-app (sino)
