const fs = require("fs");

// Lê um arquivo .env (CHAVE=valor por linha) e preenche process.env sem sobrescrever
// variáveis que já existem. Usado na KingHost, que não tem tela de variáveis de ambiente.
function carregar(arquivo) {
  let conteudo;
  try { conteudo = fs.readFileSync(arquivo, "utf8"); } catch { return; }
  for (const linha of conteudo.replace(/^﻿/, "").split(/\r?\n/)) {
    const m = linha.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (!m) continue;
    let v = m[2];
    if (/^(["']).*\1$/.test(v)) v = v.slice(1, -1);
    if (process.env[m[1]] === undefined) process.env[m[1]] = v;
  }
}

// SSL do Postgres: DATABASE_SSL=off desliga (KingHost), =on liga. Sem a variável:
// desligado para localhost, ligado para os demais (Render exige SSL).
function opcoesBanco(url, sslVar) {
  const s = String(sslVar || "").toLowerCase();
  const ssl = s === "off" || s === "false" || s === "0" ? false
    : s === "on" || s === "true" || s === "1" ? true
    : !/localhost|127\.0\.0\.1/.test(url);
  return { connectionString: url, ssl: ssl ? { rejectUnauthorized: false } : false };
}

module.exports = { carregar, opcoesBanco };
