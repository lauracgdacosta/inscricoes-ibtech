const express = require("express");
const crypto = require("crypto");
const path = require("path");

const VALOR = 400; // R$ por pessoa
const JANELA_DUPLICADA_MIN = 10; // minutos
const app = express();
app.use(express.json({ limit: "50kb" }));
app.use(express.static(path.join(__dirname, "public")));

// ---------- armazenamento ----------
let store;
if (process.env.DATABASE_URL) {
  const { Pool } = require("pg");
  const pool = new Pool({
    connectionString: process.env.DATABASE_URL,
    ssl: process.env.DATABASE_URL.includes("localhost") ? false : { rejectUnauthorized: false },
  });
  store = {
    async init() {
      await pool.query(`CREATE TABLE IF NOT EXISTS inscricoes (
        id TEXT PRIMARY KEY,
        criada_em TIMESTAMPTZ NOT NULL DEFAULT now(),
        dados JSONB NOT NULL)`);
    },
    async add(r) { await pool.query("INSERT INTO inscricoes (id, criada_em, dados) VALUES ($1,$2,$3)", [r.id, r.criadaEm, r]); },
    async list() { const q = await pool.query("SELECT dados FROM inscricoes ORDER BY criada_em DESC"); return q.rows.map(x => x.dados); },
    async recentes(min) {
      const q = await pool.query("SELECT dados FROM inscricoes WHERE criada_em > now() - make_interval(mins => $1)", [min]);
      return q.rows.map(x => x.dados);
    },
    async remove(id) { await pool.query("DELETE FROM inscricoes WHERE id=$1", [id]); },
  };
} else {
  console.warn("DATABASE_URL não definida: usando memória (apenas para testes, os dados somem ao reiniciar).");
  const mem = [];
  store = {
    async init() {},
    async add(r) { mem.unshift(r); },
    async list() { return mem.slice(); },
    async recentes(min) { const lim = Date.now() - min * 60e3; return mem.filter(r => Date.parse(r.criadaEm) > lim); },
    async remove(id) { const i = mem.findIndex(x => x.id === id); if (i >= 0) mem.splice(i, 1); },
  };
}

// ---------- utilidades ----------
const txt = (v, max = 200) => String(v ?? "").replace(/[\u0000-\u001f]/g, " ").trim().slice(0, max);
const emailOk = e => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
const brl = n => n.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
const html = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const soDigitos = s => String(s ?? "").replace(/\D/g, "");
const nomeNorm = s => String(s ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
// Express 4 não captura erros de funções async: sem isso, uma falha do banco derruba o servidor.
const seguro = fn => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

const hits = new Map(); // limite simples por IP: 10 envios/hora
function limite(req, res, next) {
  const ip = req.headers["x-forwarded-for"]?.split(",")[0].trim() || req.ip;
  const agora = Date.now();
  const lista = (hits.get(ip) || []).filter(t => agora - t < 3600e3);
  if (lista.length >= 10) return res.status(429).json({ erro: "Muitas tentativas. Tente novamente mais tarde." });
  lista.push(agora); hits.set(ip, lista); next();
}
setInterval(() => { // limpa IPs antigos para o mapa não crescer sem fim
  const agora = Date.now();
  for (const [ip, l] of hits) if (!l.some(t => agora - t < 3600e3)) hits.delete(ip);
}, 600e3).unref();

function admin(req, res, next) {
  const senha = process.env.ADMIN_PASSWORD;
  if (!senha) return res.status(503).send("Defina a variável ADMIN_PASSWORD para usar o painel.");
  const h = req.headers.authorization || "";
  const dado = Buffer.from(h.replace(/^Basic /, ""), "base64").toString();
  const informada = dado.slice(dado.indexOf(":") + 1);
  const a = Buffer.from(informada), b = Buffer.from(senha);
  if (a.length === b.length && crypto.timingSafeEqual(a, b)) return next();
  res.set("WWW-Authenticate", 'Basic realm="Painel Ibtech", charset="UTF-8"').status(401).send("Acesso restrito.");
}

// Mesmo órgão (CNPJ ou nome) e mesmo e-mail do responsável nos últimos minutos.
function ehDuplicada(nova, recentes) {
  const email = nova.email.toLowerCase(), cnpj = soDigitos(nova.cnpj), nome = nomeNorm(nova.orgao);
  return recentes.some(r => String(r.email).toLowerCase() === email &&
    ((cnpj && soDigitos(r.cnpj) === cnpj) || nomeNorm(r.orgao) === nome));
}

// Verificação + gravação em fila, para dois cliques simultâneos não passarem juntos pela checagem.
let fila = Promise.resolve();
const exclusivo = fn => { const p = fila.then(fn); fila = p.catch(() => {}); return p; };

// ---------- e-mail de confirmação (Resend, via API HTTP; sem dependências extras) ----------
const emailAtivo = () => Boolean(process.env.RESEND_API_KEY && process.env.EMAIL_FROM);

async function enviarConfirmacao(r) {
  if (!emailAtivo()) return;
  const qtd = r.participantes.length;
  const lista = r.participantes.map(p => p.nome + (p.cargo ? ` (${p.cargo})` : ""));
  const texto = [
    `Olá, ${r.responsavel}.`,
    "",
    `Recebemos a inscrição de ${r.orgao} no Treinamento presencial Ibtech, em Belo Horizonte, nos dias 01 e 02/12/2026.`,
    "",
    `Participantes (${qtd}):`,
    ...lista.map(n => "- " + n),
    "",
    `Valor total: ${brl(r.valorTotal)} (${qtd} × ${brl(VALOR)})`,
    "",
    "O local do treinamento ainda será definido e será informado posteriormente por e-mail.",
    "",
    "Equipe Ibtech",
  ].join("\n");
  const corpo = `<div style="font-family:Arial,sans-serif;color:#2b2a5c;max-width:560px">
<div style="background:#3f3e82;color:#fff;padding:18px 20px;border-bottom:4px solid #e5c438">
<b style="font-size:18px">Treinamento presencial Ibtech</b><br>01 e 02/12/2026 · Belo Horizonte</div>
<div style="padding:18px 20px;border:1px solid #d9d9ea;border-top:0">
<p>Olá, ${html(r.responsavel)}.</p>
<p>Recebemos a inscrição de <b>${html(r.orgao)}</b>.</p>
<p><b>Participantes (${qtd}):</b><br>${lista.map(html).join("<br>")}</p>
<p style="background:#e5c438;padding:10px 14px;font-size:16px"><b>Valor total: ${brl(r.valorTotal)}</b> (${qtd} × ${brl(VALOR)})</p>
<p>O local do treinamento ainda será definido e será informado posteriormente por e-mail.</p>
<p>Equipe Ibtech</p></div></div>`;
  const msg = { from: process.env.EMAIL_FROM, to: [r.email], subject: "Inscrição recebida – Treinamento Ibtech 01 e 02/12/2026", text: texto, html: corpo };
  if (process.env.EMAIL_REPLY_TO) msg.reply_to = process.env.EMAIL_REPLY_TO;
  const resp = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: { Authorization: "Bearer " + process.env.RESEND_API_KEY, "Content-Type": "application/json" },
    body: JSON.stringify(msg),
    signal: AbortSignal.timeout(15000),
  });
  if (!resp.ok) throw new Error(`Resend respondeu ${resp.status}: ${await resp.text()}`);
}

// ---------- API pública ----------
app.post("/api/inscricoes", limite, async (req, res) => {
  try {
    const b = req.body || {};
    if (b.site) return res.json({ ok: true }); // campo isca contra robôs
    const participantes = (Array.isArray(b.participantes) ? b.participantes : []).slice(0, 30).map(p => ({
      nome: txt(p?.nome), cargo: txt(p?.cargo), email: txt(p?.email, 120),
    })).filter(p => p.nome);
    const r = {
      id: crypto.randomUUID(),
      criadaEm: new Date().toISOString(),
      orgao: txt(b.orgao), tipo: txt(b.tipo, 40), cnpj: txt(b.cnpj, 25),
      responsavel: txt(b.responsavel), cargo: txt(b.cargo), email: txt(b.email, 120), telefone: txt(b.telefone, 30),
      participantes, valorTotal: participantes.length * VALOR,
      notebook: txt(b.notebook, 20), observacoes: txt(b.observacoes, 1000),
    };
    if (!r.orgao || !r.responsavel || !r.cargo || !r.telefone) return res.status(400).json({ erro: "Preencha órgão, responsável, cargo e telefone." });
    if (!emailOk(r.email)) return res.status(400).json({ erro: "Confira o e-mail informado." });
    if (!participantes.length) return res.status(400).json({ erro: "Informe ao menos um participante." });
    if (participantes.some(p => p.email && !emailOk(p.email))) return res.status(400).json({ erro: "Confira os e-mails dos participantes." });

    const gravou = await exclusivo(async () => {
      if (ehDuplicada(r, await store.recentes(JANELA_DUPLICADA_MIN))) return false;
      await store.add(r);
      return true;
    });
    if (!gravou) return res.status(409).json({ erro: "Já recebemos uma inscrição deste órgão com este e-mail há poucos minutos. Não é preciso enviar de novo. Se quiser alterar algo, fale com a equipe Ibtech." });

    res.json({ ok: true, participantes: participantes.length, valorTotal: r.valorTotal, emailConfirmacao: emailAtivo() });
    enviarConfirmacao(r).catch(e => console.error("Falha ao enviar e-mail de confirmação para", r.email, "-", e.message));
  } catch (e) { console.error(e); res.status(500).json({ erro: "Não foi possível registrar. Tente novamente." }); }
});

// ---------- painel ----------
const csvCampo = s => {
  let v = String(s ?? "");
  if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; // impede que o Excel interprete o texto como fórmula
  return '"' + v.replace(/"/g, '""') + '"';
};
app.get("/admin", admin, (_q, res) => res.sendFile(path.join(__dirname, "admin.html")));
app.get("/admin/api/inscricoes", admin, seguro(async (_q, res) => res.json(await store.list())));
app.delete("/admin/api/inscricoes/:id", admin, seguro(async (q, res) => { await store.remove(q.params.id); res.json({ ok: true }); }));
app.get("/admin/inscricoes.csv", admin, seguro(async (_q, res) => {
  const lista = await store.list();
  const linhas = [["Recebida", "Órgão", "Tipo", "CNPJ", "Responsável", "Cargo", "E-mail", "Telefone", "Participantes", "Qtd", "Valor total (R$)", "Notebook", "Observações"].map(csvCampo).join(";")]
    .concat(lista.map(r => [new Date(r.criadaEm).toLocaleString("pt-BR", { timeZone: "America/Sao_Paulo" }), r.orgao, r.tipo, r.cnpj, r.responsavel, r.cargo, r.email, r.telefone,
      r.participantes.map(p => `${p.nome}${p.cargo ? " (" + p.cargo + ")" : ""}${p.email ? " <" + p.email + ">" : ""}`).join(" | "),
      r.participantes.length, r.valorTotal, r.notebook, r.observacoes].map(csvCampo).join(";")));
  res.set({ "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": 'attachment; filename="inscricoes-treinamento-ibtech.csv"' });
  res.send("﻿" + linhas.join("\r\n")); // BOM para o Excel reconhecer os acentos
}));

app.get("/healthz", (_q, res) => res.send("ok"));

// Erros (inclusive JSON malformado) sempre em JSON e em português, sem expor detalhes internos.
app.use((err, req, res, _next) => {
  if (err.type === "entity.parse.failed") return res.status(400).json({ erro: "Dados inválidos. Recarregue a página e tente novamente." });
  if (err.type === "entity.too.large") return res.status(413).json({ erro: "Inscrição grande demais. Reduza as observações ou o número de participantes." });
  console.error(err);
  res.status(500).json({ erro: "Erro interno. Tente novamente." });
});

const PORT = process.env.PORT || 3000;
store.init()
  .then(() => app.listen(PORT, () => console.log("Rodando na porta " + PORT + (emailAtivo() ? " (e-mail de confirmação ativo)" : " (e-mail de confirmação desativado)"))))
  .catch(e => { console.error("Não foi possível conectar ao banco:", e.message); process.exit(1); });
