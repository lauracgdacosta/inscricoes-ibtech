// Copia as inscrições de um banco para outro (ex.: Render -> KingHost).
//
// Uso:
//   node migrar-banco.js <origem> [destino]
//
// <origem>  = URL do banco antigo (postgres://...) OU um arquivo backup-*.json gerado antes.
// [destino] = URL do banco novo, OU o endereço do site novo em PHP (https://...), que recebe
//             os dados pelo painel (precisa de ADMIN_PASSWORD com a senha do painel do site novo).
//             Se omitido, usa DATABASE_URL do .env.
//             Use "-" para apenas gerar o arquivo de backup, sem gravar em lugar nenhum.
//
// Sempre grava um backup-AAAA-MM-DD-HHMM.json com todas as inscrições lidas.
// Não apaga nada: inscrições que já existem no destino (mesmo id) são mantidas.
const fs = require("fs");
const path = require("path");
const { Client } = require("pg");
const { carregar, opcoesBanco } = require("./env");
carregar(path.join(__dirname, ".env"));

const [origem, destinoArg] = process.argv.slice(2);
const destino = destinoArg || process.env.DATABASE_URL;
if (!origem) {
  console.error("Informe a origem: node migrar-banco.js <URL do banco antigo ou arquivo .json> [URL do banco novo]");
  process.exit(1);
}
const ehUrl = s => /^postgres(ql)?:\/\//i.test(s);
const oculta = u => u.replace(/\/\/([^:@/]+):[^@]*@/, "//$1:****@");

async function lerOrigem() {
  if (!ehUrl(origem)) {
    console.log("Lendo arquivo " + origem);
    return JSON.parse(fs.readFileSync(origem, "utf8"));
  }
  console.log("Lendo banco de origem " + oculta(origem));
  const c = new Client(opcoesBanco(origem, process.env.ORIGEM_SSL));
  await c.connect();
  try {
    const q = await c.query("SELECT id, criada_em, dados FROM inscricoes ORDER BY criada_em");
    return q.rows.map(r => ({ id: r.id, criada_em: new Date(r.criada_em).toISOString(), dados: r.dados }));
  } finally { await c.end(); }
}

async function gravarDestino(linhas) {
  console.log("Gravando no banco de destino " + oculta(destino));
  const c = new Client(opcoesBanco(destino, process.env.DATABASE_SSL));
  await c.connect();
  try {
    await c.query(`CREATE TABLE IF NOT EXISTS inscricoes (
      id TEXT PRIMARY KEY,
      criada_em TIMESTAMPTZ NOT NULL DEFAULT now(),
      dados JSONB NOT NULL)`);
    let novas = 0;
    await c.query("BEGIN");
    for (const r of linhas) {
      const q = await c.query(
        "INSERT INTO inscricoes (id, criada_em, dados) VALUES ($1,$2,$3) ON CONFLICT (id) DO NOTHING",
        [r.id, r.criada_em, JSON.stringify(r.dados)]);
      novas += q.rowCount;
    }
    await c.query("COMMIT");
    const total = (await c.query("SELECT count(*)::int AS n FROM inscricoes")).rows[0].n;
    console.log(`Copiadas ${novas} inscrições novas (${linhas.length - novas} já existiam). Total no destino: ${total}.`);
  } catch (e) {
    await c.query("ROLLBACK").catch(() => {});
    throw e;
  } finally { await c.end(); }
}

// Destino = endereço do site novo (versão PHP): envia para /admin/api/importar com a senha do painel.
async function enviarSite(linhas) {
  const senha = process.env.ADMIN_PASSWORD;
  if (!senha) throw new Error("Defina ADMIN_PASSWORD (senha do painel do site novo) para enviar ao site.");
  const url = destino.replace(/\/+$/, "") + "/admin/api/importar";
  console.log("Enviando para " + url);
  const r = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: "Basic " + Buffer.from("migracao:" + senha).toString("base64") },
    body: JSON.stringify(linhas),
  });
  const t = await r.text();
  if (!r.ok) throw new Error(`O site respondeu ${r.status}: ${t.slice(0, 300)}`);
  const j = JSON.parse(t);
  console.log(`Recebidas ${j.recebidas}, novas ${j.novas} (as demais já existiam).`);
}

(async () => {
  const linhas = await lerOrigem();
  console.log(`Encontradas ${linhas.length} inscrições.`);
  if (ehUrl(origem)) {
    const carimbo = new Date().toISOString().slice(0, 16).replace("T", "-").replace(":", "");
    const arq = path.join(__dirname, `backup-${carimbo}.json`);
    fs.writeFileSync(arq, JSON.stringify(linhas, null, 2));
    console.log("Backup salvo em " + arq);
  }
  if (!destino || destino === "-") return console.log("Sem destino: nada gravado (apenas backup).");
  if (/^https?:\/\//i.test(destino)) return enviarSite(linhas);
  await gravarDestino(linhas);
})().catch(e => { console.error("ERRO: " + e.message); process.exit(1); });
