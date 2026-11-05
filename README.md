# Inscrições – Treinamento Ibtech (01 e 02/12/2026)

- Formulário público: `/`
- Painel da equipe (senha): `/admin` (usuário qualquer, senha = variável `ADMIN_PASSWORD`)
- Planilha: botão "Baixar planilha (CSV)" no painel
- Logo: arquivo `public/logo.png` (se não existir, o topo aparece só com o texto)

## Variáveis de ambiente

| Variável | Obrigatória | Para que serve |
|---|---|---|
| `ADMIN_PASSWORD` | sim | Senha do painel `/admin` |
| `DATABASE_URL` | sim (no Render é automática) | Banco Postgres. Sem ela, guarda em memória (só para testes) |
| `RESEND_API_KEY` | não | Chave da Resend para o e-mail de confirmação |
| `EMAIL_FROM` | não | Remetente, ex.: `Ibtech <inscricoes@seudominio.com.br>` (domínio verificado na Resend) |
| `EMAIL_REPLY_TO` | não | Endereço que recebe as respostas ao e-mail de confirmação |

Sem `RESEND_API_KEY` e `EMAIL_FROM` o e-mail não é enviado e a inscrição funciona normalmente.
Uma falha no envio aparece no log do Render e não impede a inscrição.

## Painel: financeiro e e-mail
- Cada inscrição tem situação financeira: Pendente, Empenhado, Pago, Isento ou Cancelado, com forma de pagamento, nº do empenho, nº da nota fiscal, data e valor pago (aceita pagamento parcial).
- Totais no topo: valor previsto (sem isentos e cancelados), recebido e a receber. Filtro por situação e busca.
- Coluna "E-mail de confirmação": mostra se o e-mail saiu ou o erro devolvido pela Resend, com botão para enviar de novo.
- O CSV inclui todas as colunas financeiras e o saldo.

## Regras
- Valor: R$ 400,00 por participante.
- Inscrição duplicada: o mesmo órgão (mesmo CNPJ ou mesmo nome, sem diferenciar acentos e maiúsculas) com o mesmo e-mail de responsável em menos de 10 minutos é recusada.
- Limite de 10 envios por hora por IP.

## Publicar no Render
1. Suba esta pasta para um repositório no GitHub (sem a pasta `node_modules`).
2. No Render: New > Blueprint > escolha o repositório (usa o `render.yaml`, que cria o site e o banco).
3. Preencha as variáveis pedidas (veja a tabela acima).
4. O endereço público aparece no painel do Render (ex.: https://inscricoes-ibtech.onrender.com).

Observação: no plano gratuito o Render desliga o site após 15 minutos sem acesso. Para evitar isso, o próprio site acessa o seu endereço público (`/healthz`) a cada 10 minutos (auto-ping, ativo apenas no Render; para desligar, crie a variável `KEEPALIVE=off`). Se o Render reiniciar o serviço por manutenção, o primeiro acesso seguinte demora cerca de 1 minuto e o auto-ping volta a funcionar. O banco gratuito do Render expira 30 dias após a criação: baixe o CSV antes do evento.

## Testar no computador
npm install && ADMIN_PASSWORD=teste npm start  (sem DATABASE_URL guarda em memória)
