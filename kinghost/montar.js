// Monta a pasta "publicar-kinghost" com tudo que deve ser enviado por FTP para a KingHost.
// Uso: node kinghost/montar.js
const fs = require("fs");
const path = require("path");
const raiz = path.join(__dirname, "..");
const destino = path.join(raiz, "publicar-kinghost");
fs.mkdirSync(destino, { recursive: true });
const arquivos = {
  "app.php": "kinghost/app.php",
  ".htaccess": "kinghost/.htaccess",
  "admin.html": "admin.html",
  "inscricao.html": "inscricao.html",
  "index.html": "public/index.html",
  "logo.png": "public/logo.png",
};
for (const [nome, origem] of Object.entries(arquivos)) fs.copyFileSync(path.join(raiz, origem), path.join(destino, nome));
const cfg = path.join(destino, "config.php");
if (!fs.existsSync(cfg)) fs.copyFileSync(path.join(__dirname, "config.exemplo.php"), cfg);
console.log("Pasta pronta: " + destino + "\nPreencha o config.php antes de enviar (se ainda não preencheu).");
