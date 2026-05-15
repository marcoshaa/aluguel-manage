<?php
function geminiChat(string $pergunta): string {
    $db = getDB();

    // Contexto: imóveis
    $imoveis = $db->query("SELECT endereco, tipo, valor_aluguel, status FROM imoveis")->fetchAll();
    // Contexto: inquilinos
    $inquilinos = $db->query("SELECT nome, cpf, telefone, email FROM inquilinos")->fetchAll();
    // Contexto: contratos ativos
    $contratos = $db->query("
        SELECT c.id, i.endereco, q.nome AS inquilino, c.valor_mensal, c.dia_vencimento,
               c.data_inicio, c.data_fim, c.indice_reajuste
        FROM contratos c
        JOIN imoveis i ON i.id = c.imovel_id
        JOIN inquilinos q ON q.id = c.inquilino_id
        WHERE c.ativo = 1
    ")->fetchAll();
    // Contexto: pagamentos recentes (últimos 3 meses)
    $pagamentos = $db->query("
        SELECT p.mes_referencia, p.status, p.valor_pago, p.data_pagamento,
               q.nome AS inquilino, i.endereco
        FROM pagamentos p
        JOIN contratos c ON c.id = p.contrato_id
        JOIN inquilinos q ON q.id = c.inquilino_id
        JOIN imoveis i ON i.id = c.imovel_id
        ORDER BY p.mes_referencia DESC
        LIMIT 60
    ")->fetchAll();

    $contexto  = "=== IMÓVEIS ===\n";
    foreach ($imoveis as $im) {
        $contexto .= "- {$im['endereco']} ({$im['tipo']}) | Aluguel: R$ {$im['valor_aluguel']} | Status: {$im['status']}\n";
    }

    $contexto .= "\n=== INQUILINOS ===\n";
    foreach ($inquilinos as $iq) {
        $contexto .= "- {$iq['nome']} | CPF: {$iq['cpf']} | Tel: {$iq['telefone']} | Email: {$iq['email']}\n";
    }

    $contexto .= "\n=== CONTRATOS ATIVOS ===\n";
    foreach ($contratos as $ct) {
        $contexto .= "- Imóvel: {$ct['endereco']} | Inquilino: {$ct['inquilino']} | Valor: R$ {$ct['valor_mensal']} | Venc. dia {$ct['dia_vencimento']} | Início: {$ct['data_inicio']}" . ($ct['data_fim'] ? " | Fim: {$ct['data_fim']}" : '') . " | Reajuste: {$ct['indice_reajuste']}\n";
    }

    $contexto .= "\n=== PAGAMENTOS RECENTES ===\n";
    foreach ($pagamentos as $pg) {
        $pago = $pg['valor_pago'] ? "R$ {$pg['valor_pago']} em {$pg['data_pagamento']}" : 'não registrado';
        $contexto .= "- {$pg['inquilino']} | {$pg['endereco']} | {$pg['mes_referencia']} | Status: {$pg['status']} | {$pago}\n";
    }

    $prompt = "Você é um assistente de gestão de aluguéis. Responda em português, de forma clara e direta, baseado apenas nos dados abaixo. Se não souber, diga que não tem a informação.\n\nQuando o usuário pedir para gerar, exportar ou baixar uma planilha/Excel/relatório, responda APENAS com uma tag no formato:\n[EXPORTAR:tipo:filtro]\nOnde:\n- tipo: pagamentos | imoveis | inquilinos | contratos\n- filtro (opcional): atrasado | pendente | pago | YYYY-MM\nExemplos:\n'quem está devendo e gera excel' → [EXPORTAR:pagamentos:atrasado]\n'exportar pagamentos de maio 2026' → [EXPORTAR:pagamentos:2026-05]\n'gerar excel de imóveis' → [EXPORTAR:imoveis:]\nNão adicione mais nada na resposta quando retornar uma tag [EXPORTAR].\n\nDADOS DO SISTEMA:\n{$contexto}\n\nPERGUNTA: {$pergunta}";

    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 800]
    ]);

    $apiKey = function_exists('getGeminiKey') ? getGeminiKey() : (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
    if (!$apiKey) return 'Chave API Gemini não configurada. Acesse Meu Perfil para adicionar.';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return "Erro de conexão com a IA: {$err}";

    $data = json_decode($resp, true);
    return $data['candidates'][0]['content']['parts'][0]['text']
        ?? ($data['error']['message'] ?? 'Resposta inválida da IA.');
}
