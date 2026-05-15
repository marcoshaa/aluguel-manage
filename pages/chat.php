<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();

layoutHead('Chat IA');
?>

<div class="chat-wrap">
    <div class="chat-messages" id="chat-messages">
        <div class="msg msg-ai">Olá! Sou o assistente de gestão de aluguéis. Posso responder perguntas como:<br><br>
• "Quem está atrasado esse mês?"<br>
• "Qual o total de receita de abril?"<br>
• "Quais imóveis estão disponíveis?"<br>
• "Gera um Excel com os pagamentos atrasados"<br><br>
Como posso ajudar?</div>
    </div>
    <form class="chat-input-bar" id="chat-form" autocomplete="off">
        <input type="text" id="chat-input" placeholder="Digite sua pergunta..." required autofocus>
        <button type="submit" class="btn btn-primary">Enviar</button>
    </form>
</div>

<script>
window.chatApiPath = '<?= rtrim(str_replace("\\", "/", dirname(dirname($_SERVER['SCRIPT_NAME']))), "/") ?>/api/chat.php';
</script>

<?php layoutFoot(); ?>
