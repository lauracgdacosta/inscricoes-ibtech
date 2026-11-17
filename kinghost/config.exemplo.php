<?php
// Copie este arquivo para "config.php" (na mesma pasta, no servidor) e preencha.
// O config.php NÃO vai para o GitHub.
return [
  'ADMIN_PASSWORD' => 'troque-esta-senha',

  // Banco criado no painel da KingHost: 'mysql' ou 'pgsql'
  'DB_TIPO'     => 'mysql',
  'DB_SERVIDOR' => 'mysql.seudominio.com.br',
  'DB_PORTA'    => '',   // vazio = padrão (3306 MySQL, 5432 PostgreSQL)
  'DB_NOME'     => '',
  'DB_USUARIO'  => '',
  'DB_SENHA'    => '',

  // E-mail de confirmação (Resend)
  'RESEND_API_KEY' => '',
  'EMAIL_FROM'     => 'Ibtech <inscricoes@ibtechti.com.br>',
  'EMAIL_REPLY_TO' => '',

  'FINANCEIRO_TELEFONE' => '',
];
