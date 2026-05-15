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
