<?php
// Inscrições – Treinamento Ibtech: versão PHP para hospedagem compartilhada (KingHost).
// Mesmas regras e mesma API do server.js. Configuração em config.php (veja config.exemplo.php).
// Compatível com PHP 7.4+ e banco MySQL ou PostgreSQL.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

// ---------- configuração ----------
$CFG = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function cfg(string $k, string $padrao = ''): string { global $CFG; return isset($CFG[$k]) && (string)$CFG[$k] !== '' ? (string)$CFG[$k] : $padrao; }

// Preço: cada grupo de 3 participantes custa R$ 1.000; quem sobra paga R$ 400 por pessoa.
const VALOR = 400, GRUPO = 3, VALOR_GRUPO = 1000, JANELA_DUPLICADA_MIN = 10;
const SITUACOES = ['Pendente', 'Empenhado', 'Pago', 'Isento', 'Cancelado'];
const FORMAS = ['', 'Transferência', 'Boleto', 'PIX', 'Outro'];
function valorDe(int $n): int { return intdiv($n, GRUPO) * VALOR_GRUPO + ($n % GRUPO) * VALOR; }
function brl($n): string { return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function composicao(int $n): string {
  $g = intdiv($n, GRUPO); $r = $n % GRUPO; $p = [];
  if ($g) $p[] = "$g × grupo de 3 (" . brl(VALOR_GRUPO) . ')';
  if ($r) $p[] = "$r × " . brl(VALOR);
  return implode(' + ', $p);
}

// ---------- utilidades ----------
function corta(string $s, int $max): string { return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max); }
function txt($v, int $max = 200): string {
  if (is_array($v) || is_object($v)) $v = '';
  $s = preg_replace('/[\x00-\x1f]/', ' ', (string)($v ?? ''));
  return corta(trim((string)$s), $max);
}
function emailOk(string $e): bool { return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $e); }
function soDigitos($s): string { return preg_replace('/\D/', '', (string)($s ?? '')); }
function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function semAcento(string $s): string {
  if (class_exists('Normalizer')) return preg_replace('/\p{Mn}/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
  return strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
    'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
}
function nomeNorm($s): string { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(semAcento((string)($s ?? ''))))); }
function cnpjOk($c): bool {
  $d = soDigitos($c);
  if (strlen($d) !== 14 || preg_match('/^(\d)\1+$/', $d)) return false;
  $dv = function (int $n) use ($d): int { $s = 0; $p = $n - 7; for ($i = 0; $i < $n; $i++) { $s += (int)$d[$i] * $p--; if ($p < 2) $p = 9; } $r = $s % 11; return $r < 2 ? 0 : 11 - $r; };
  return $dv(12) === (int)$d[12] && $dv(13) === (int)$d[13];
}
function cnpjFmt($c): string { return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', soDigitos($c)); }
function nomeCompleto(string $n): bool { return count(preg_split('/\s+/', $n, -1, PREG_SPLIT_NO_EMPTY)) >= 2; }
function agoraIso(): string { $t = microtime(true); return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000)); }
function uuid4(): string {
  $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function finDe(array $r): array {
  return array_merge(['situacao' => 'Pendente', 'forma' => '', 'empenho' => '', 'notaFiscal' => '', 'dataPagamento' => '', 'valorPago' => 0, 'obs' => ''],
    isset($r['financeiro']) && is_array($r['financeiro']) ? $r['financeiro'] : []);
}
function jsonEnc($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE); }
function responder(int $status, $dados): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo jsonEnc($dados);
}
function erro(int $status, string $msg): void { responder($status, ['erro' => $msg]); exit; }
function ipCliente(): string {
  $xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  $ip = $xf !== '' ? trim(explode(',', $xf)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
  return substr($ip, 0, 64);
}
function corpoJson(): array {
  $raw = file_get_contents('php://input');
  if (strlen((string)$raw) > 50 * 1024 && !($GLOBALS['CORPO_GRANDE'] ?? false)) erro(413, 'Inscrição grande demais. Reduza as observações ou o número de participantes.');
  $j = json_decode((string)$raw, true);
  if (!is_array($j)) erro(400, 'Dados inválidos. Recarregue a página e tente novamente.');
  return $j;
}

// ---------- banco ----------
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $tipo = cfg('DB_TIPO', 'mysql');
  if ($tipo === 'sqlite') {
    $arq = cfg('DB_ARQUIVO', __DIR__ . '/dados/inscricoes.sqlite');
    if (!is_dir(dirname($arq))) mkdir(dirname($arq), 0700, true);
    $dsn = 'sqlite:' . $arq;
  }
  elseif ($tipo === 'pgsql') $dsn ='pgsql:host=' . cfg('DB_SERVIDOR') . ';port=' . cfg('DB_PORTA', '5432') . ';dbname=' . cfg('DB_NOME');
  else $dsn = 'mysql:host=' . cfg('DB_SERVIDOR') . ';port=' . cfg('DB_PORTA', '3306') . ';dbname=' . cfg('DB_NOME') . ';charset=utf8mb4';
  $pdo = new PDO($dsn, cfg('DB_USUARIO'), cfg('DB_SENHA'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  if ($tipo === 'pgsql' || $tipo === 'sqlite') {
    if ($tipo === 'sqlite') $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS inscricoes (id VARCHAR(64) PRIMARY KEY, criada_em VARCHAR(30) NOT NULL, dados TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentativas (ip VARCHAR(64) NOT NULL, em BIGINT NOT NULL)');
  } else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS inscricoes (id VARCHAR(64) PRIMARY KEY, criada_em VARCHAR(30) NOT NULL, dados MEDIUMTEXT NOT NULL, INDEX (criada_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tentativas (ip VARCHAR(64) NOT NULL, em BIGINT NOT NULL, INDEX (ip)) ENGINE=InnoDB');
  }
  return $pdo;
}
function ehPg(): bool { return cfg('DB_TIPO', 'mysql') === 'pgsql'; }
function ehSqlite(): bool { return cfg('DB_TIPO', 'mysql') === 'sqlite'; }
function decodifica(array $linhas): array { return array_values(array_filter(array_map(function ($l) { return json_decode($l['dados'], true); }, $linhas), 'is_array')); }
function lista(): array { return decodifica(db()->query('SELECT dados FROM inscricoes ORDER BY criada_em DESC')->fetchAll()); }
function recentes(int $min): array {
  $st = db()->prepare('SELECT dados FROM inscricoes WHERE criada_em > ?');
  $st->execute([gmdate('Y-m-d\TH:i:s', time() - $min * 60)]);
  return decodifica($st->fetchAll());
}
function obter(string $id): ?array {
  $st = db()->prepare('SELECT dados FROM inscricoes WHERE id = ?'); $st->execute([$id]);
  $l = $st->fetch(); return $l ? json_decode($l['dados'], true) : null;
}
function inserir(array $r): bool {
  $sql = ehPg() ? 'INSERT INTO inscricoes (id, criada_em, dados) VALUES (?,?,?) ON CONFLICT (id) DO NOTHING'
       : (ehSqlite() ? 'INSERT OR IGNORE INTO inscricoes (id, criada_em, dados) VALUES (?,?,?)'
                     : 'INSERT IGNORE INTO inscricoes (id, criada_em, dados) VALUES (?,?,?)');
  $st = db()->prepare($sql); $st->execute([$r['id'], $r['criadaEm'], jsonEnc($r)]);
  return $st->rowCount() > 0;
}
function setCampo(string $id, string $campo, $valor): bool {
  $pdo = db(); $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT dados FROM inscricoes WHERE id = ?' . (ehSqlite() ? '' : ' FOR UPDATE')); $st->execute([$id]);
    $l = $st->fetch();
    if (!$l) { $pdo->rollBack(); return false; }
    $d = json_decode($l['dados'], true); $d[$campo] = $valor;
    $pdo->prepare('UPDATE inscricoes SET dados = ? WHERE id = ?')->execute([jsonEnc($d), $id]);
    $pdo->commit(); return true;
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
// Trava global para a verificação de duplicada + gravação não rodarem em paralelo.
function travar(): void {
  if (ehSqlite()) db()->exec('BEGIN IMMEDIATE');
  elseif (ehPg()) db()->query('SELECT pg_advisory_lock(424242)');
  else db()->query("SELECT GET_LOCK('inscricoes_ibtech', 15)");
}
function destravar(): void {
  if (ehSqlite()) db()->exec('COMMIT');
  elseif (ehPg()) db()->query('SELECT pg_advisory_unlock(424242)');
  else db()->query("SELECT RELEASE_LOCK('inscricoes_ibtech')");
}

// Limite simples por IP: 10 envios por hora.
function limite(): void {
  $agora = time(); $ip = ipCliente();
  db()->prepare('DELETE FROM tentativas WHERE em < ?')->execute([$agora - 3600]);
  $st = db()->prepare('SELECT COUNT(*) AS n FROM tentativas WHERE ip = ?'); $st->execute([$ip]);
  if ((int)$st->fetch()['n'] >= 10) erro(429, 'Muitas tentativas. Tente novamente mais tarde.');
  db()->prepare('INSERT INTO tentativas (ip, em) VALUES (?, ?)')->execute([$ip, $agora]);
}

// ---------- autenticação do painel (usuário qualquer, senha = ADMIN_PASSWORD) ----------
function admin(): void {
  $senha = cfg('ADMIN_PASSWORD');
  if ($senha === '') { http_response_code(503); echo 'Defina ADMIN_PASSWORD no config.php para usar o painel.'; exit; }
  $informada = $_SERVER['PHP_AUTH_PW'] ?? null;
  if ($informada === null) {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Basic ') === 0) {
      $dado = (string)base64_decode(substr($hdr, 6));
      $p = strpos($dado, ':');
      $informada = $p === false ? '' : substr($dado, $p + 1);
    }
  }
  if ($informada !== null && hash_equals($senha, (string)$informada)) return;
  header('WWW-Authenticate: Basic realm="Painel Ibtech", charset="UTF-8"');
  http_response_code(401); echo 'Acesso restrito.'; exit;
}

// ---------- e-mail de confirmação (Resend) ----------
function emailAtivo(): bool { return cfg('RESEND_API_KEY') !== '' && cfg('EMAIL_FROM') !== ''; }
function enviarConfirmacao(array $r): void {
  $qtd = count($r['participantes']);
  $lista = array_map(function ($p) { return $p['nome'] . (!empty($p['cargo']) ? " ({$p['cargo']})" : ''); }, $r['participantes']);
  $texto = implode("\n", array_merge([
    "Olá, {$r['responsavel']}.", '',
    "Recebemos a inscrição de {$r['orgao']} no Treinamento presencial Ibtech, em Belo Horizonte, nos dias 01 e 02/12/2026.", '',
    "Participantes ($qtd):",
  ], array_map(function ($n) { return "- $n"; }, $lista), [
    '', 'Valor total: ' . brl($r['valorTotal']) . ' (' . composicao($qtd) . ')',
    'A nota fiscal será gerada em breve. Dúvidas: fale com o nosso financeiro pelo número (31) 98787-4278.', '',
    '📍 Localização: Rua Rio Grande do Norte, 1436, sala 1305, Bairro Savassi, Belo Horizonte, MG', '',
    'Equipe Ibtech',
  ]));
  $corpo = '<div style="font-family:Arial,sans-serif;color:#2b2a5c;max-width:560px">
<div style="background:#3f3e82;color:#fff;padding:18px 20px;border-bottom:4px solid #e5c438">
<b style="font-size:18px">Treinamento presencial Ibtech</b><br>01 e 02/12/2026 · Belo Horizonte</div>
<div style="padding:18px 20px;border:1px solid #d9d9ea;border-top:0">
<p>Olá, ' . h($r['responsavel']) . '.</p>
<p>Recebemos a inscrição de <b>' . h($r['orgao']) . '</b>.</p>
<p><b>Participantes (' . $qtd . '):</b><br>' . implode('<br>', array_map('h', $lista)) . '</p>
<p style="background:#e5c438;padding:10px 14px;font-size:16px"><b>Valor total: ' . brl($r['valorTotal']) . '</b> (' . composicao($qtd) . ')</p>
<p style="font-size:14px;color:#5e5d86">A nota fiscal será gerada em breve. Dúvidas: fale com o nosso financeiro pelo número <b>(31) 98787-4278</b>.</p>
<p><b>📍 Localização:</b> Rua Rio Grande do Norte, 1436, sala 1305, Bairro Savassi, Belo Horizonte, MG</p>
<p>Equipe Ibtech</p></div></div>';
  $msg = ['from' => cfg('EMAIL_FROM'), 'to' => [$r['email']], 'subject' => 'Inscrição recebida – Treinamento Ibtech 01 e 02/12/2026 (Não responda)', 'text' => $texto, 'html' => $corpo];
  if (cfg('EMAIL_REPLY_TO') !== '') $msg['reply_to'] = cfg('EMAIL_REPLY_TO');
  $ch = curl_init('https://api.resend.com/emails');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cfg('RESEND_API_KEY'), 'Content-Type: application/json'], CURLOPT_POSTFIELDS => jsonEnc($msg)]);
  $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $falha = curl_error($ch); curl_close($ch);
  if ($resp === false) throw new RuntimeException('Falha de conexão com a Resend: ' . $falha);
  if ($status < 200 || $status >= 300) {
    $j = json_decode((string)$resp, true);
    throw new RuntimeException("Resend recusou ($status): " . (is_array($j) && isset($j['message']) ? $j['message'] : $resp));
  }
}
function confirmarERegistrar(array $r): array {
  if (!emailAtivo()) $status = ['situacao' => 'desativado', 'em' => agoraIso()];
  else {
    try { enviarConfirmacao($r); $status = ['situacao' => 'enviado', 'em' => agoraIso()]; }
    catch (Throwable $e) {
      error_log('Falha ao enviar e-mail de confirmação para ' . $r['email'] . ' - ' . $e->getMessage());
      $status = ['situacao' => 'erro', 'erro' => txt($e->getMessage(), 300), 'em' => agoraIso()];
    }
  }
  try { setCampo($r['id'], 'emailConfirmacao', $status); } catch (Throwable $e) { error_log('Falha ao registrar status do e-mail: ' . $e->getMessage()); }
  return $status;
}
// Entrega a resposta ao navegador e continua rodando (para enviar o e-mail sem fazer a pessoa esperar).
function encerrarResposta(int $status, $dados): void {
  ignore_user_abort(true);
  $corpo = jsonEnc($dados);
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Length: ' . strlen($corpo));
  header('Connection: close');
  echo $corpo;
  if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
  while (ob_get_level() > 0) ob_end_flush();
  flush();
}

// ---------- planilhas ----------
function csvCampo($s): string {
  $v = (string)($s ?? '');
  if (preg_match('/^[=+\-@\t\r]/', $v)) $v = "'" . $v; // impede que o Excel interprete o texto como fórmula
  return '"' . str_replace('"', '""', $v) . '"';
}
function enviarCsv(string $nome, array $linhas): void {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $nome . '"');
  echo "\xEF\xBB\xBF" . implode("\r\n", array_map(function ($l) { return implode(';', array_map('csvCampo', $l)); }, $linhas));
}
function dataBR(string $d): string { return $d ? implode('/', array_reverse(explode('-', $d))) : ''; }
function reais($n): string { return number_format((float)$n, 2, ',', ''); }
function horaBrasilia(string $iso, string $fmt = 'd/m/Y, H:i:s'): string {
  try { return (new DateTime($iso))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format($fmt); } catch (Throwable $e) { return $iso; }
}
function participantesTexto(array $r): string {
  return implode(' | ', array_map(function ($p) {
    return $p['nome'] . (!empty($p['cargo']) ? " ({$p['cargo']})" : '') . (!empty($p['email']) ? " <{$p['email']}>" : '');
  }, $r['participantes'] ?? []));
}
function saldo(array $r, array $f): float {
  $sem = $f['situacao'] === 'Isento' || $f['situacao'] === 'Cancelado';
  return $sem ? 0.0 : max(0.0, (float)$r['valorTotal'] - (float)$f['valorPago']);
}

// Gerador mínimo de .xlsx (sem bibliotecas externas; usa ZipArchive).
function colLetra(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xmlTxt($s): string { return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function serialExcel(int $ts): float { return $ts / 86400 + 25569; }
// $colunas: [[título, largura, estilo]] estilo: 0 texto, 2 moeda, 3 data e hora, 4 data. $linhas: valores (string, número ou null).
function planilhaXml(array $colunas, array $linhas): string {
  $n = count($linhas) + 1; $ult = colLetra(count($colunas) - 1);
  $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
  foreach ($colunas as $i => $c) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $c[1] . '" customWidth="1"/>';
  $x .= '</cols><sheetData><row r="1">';
  foreach ($colunas as $i => $c) $x .= '<c r="' . colLetra($i) . '1" t="inlineStr" s="1"><is><t>' . xmlTxt($c[0]) . '</t></is></c>';
  $x .= '</row>';
  foreach ($linhas as $li => $l) {
    $r = $li + 2; $x .= '<row r="' . $r . '">';
    foreach ($colunas as $i => $c) {
      $v = $l[$i] ?? null; $ref = colLetra($i) . $r; $s = $c[2] ? ' s="' . $c[2] . '"' : '';
      if ($v === null || $v === '') continue;
      if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
      else $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xmlTxt($v) . '</t></is></c>';
    }
    $x .= '</row>';
  }
  return $x . '</sheetData><autoFilter ref="A1:' . $ult . $n . '"/></worksheet>';
}
function xlsx(array $abas): string {
  if (!class_exists('ZipArchive')) throw new RuntimeException('Extensão zip do PHP não disponível: use o CSV.');
  $arq = tempnam(sys_get_temp_dir(), 'xlsx');
  $z = new ZipArchive(); $z->open($arq, ZipArchive::OVERWRITE);
  $tipos = ''; $rels = ''; $sheets = ''; $nomes = '';
  foreach ($abas as $i => $a) {
    $k = $i + 1;
    $z->addFromString("xl/worksheets/sheet$k.xml", planilhaXml($a['colunas'], $a['linhas']));
    $tipos .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
    $sheets .= '<sheet name="' . xmlTxt($a['nome']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
    $nomes .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . xmlTxt($a['nome']) . '\'!$A$1:$' . colLetra(count($a['colunas']) - 1) . '$' . (count($a['linhas']) + 1) . '</definedName>';
  }
  $n = count($abas) + 1;
  $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $tipos . '</Types>');
  $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets><definedNames>' . $nomes . '</definedNames></workbook>');
  $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="3"><numFmt numFmtId="164" formatCode="&quot;R$&quot; #,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="166" formatCode="dd/mm/yyyy"/></numFmts>'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF3F3E82"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
  $z->close();
  $bin = (string)file_get_contents($arq); @unlink($arq);
  return $bin;
}

// ---------- rotas ----------
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$caminho = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($base !== '' && strpos($caminho, $base) === 0) $caminho = substr($caminho, strlen($base));
$caminho = '/' . ltrim($caminho, '/');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  if ($caminho === '/healthz') { echo 'ok'; exit; }
  if ($caminho === '/' || $caminho === '/index.php') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/index.html'); exit; }

  if ($caminho === '/api/info' && $metodo === 'GET') { responder(200, ['financeiroTelefone' => txt(cfg('FINANCEIRO_TELEFONE'), 40)]); exit; }

  if ($caminho === '/api/inscricoes' && $metodo === 'POST') {
    $b = corpoJson();
    if (!empty($b['site'])) { responder(200, ['ok' => true]); exit; } // campo isca contra robôs
    limite();
    $ps = [];
    foreach (array_slice(is_array($b['participantes'] ?? null) ? $b['participantes'] : [], 0, 30) as $p) {
      $p = is_array($p) ? $p : [];
      $q = ['nome' => preg_replace('/\s+/u', ' ', txt($p['nome'] ?? '')), 'cargo' => txt($p['cargo'] ?? ''), 'email' => txt($p['email'] ?? '', 120)];
      if ($q['nome'] !== '' || $q['email'] !== '') $ps[] = $q;
    }
    $r = [
      'id' => uuid4(), 'criadaEm' => agoraIso(),
      'orgao' => txt($b['orgao'] ?? ''), 'tipo' => txt($b['tipo'] ?? '', 40), 'cnpj' => txt($b['cnpj'] ?? '', 25),
      'responsavel' => txt($b['responsavel'] ?? ''), 'cargo' => txt($b['cargo'] ?? ''), 'email' => txt($b['email'] ?? '', 120), 'telefone' => txt($b['telefone'] ?? '', 30),
      'participantes' => $ps, 'valorTotal' => valorDe(count($ps)),
      'notebook' => txt($b['notebook'] ?? '', 20), 'observacoes' => txt($b['observacoes'] ?? '', 1000),
    ];
    if (!$r['orgao'] || !$r['responsavel'] || !$r['cargo'] || !$r['telefone']) erro(400, 'Preencha órgão, responsável, cargo e telefone.');
    if (!$r['cnpj']) erro(400, 'Informe o CNPJ do órgão.');
    if (!cnpjOk($r['cnpj'])) erro(400, 'CNPJ inválido. Confira os números.');
    $r['cnpj'] = cnpjFmt($r['cnpj']);
    if (!emailOk($r['email'])) erro(400, 'Confira o e-mail informado.');
    if (!$ps) erro(400, 'Informe ao menos um participante.');
    foreach ($ps as $p) if (!nomeCompleto($p['nome'])) erro(400, 'Informe o nome completo de cada participante (é usado no certificado).');
    foreach ($ps as $p) if (!emailOk($p['email'])) erro(400, 'Informe um e-mail válido para cada participante (é usado para o certificado).');

    travar();
    try {
      $email = strtolower($r['email']); $cnpj = soDigitos($r['cnpj']); $nome = nomeNorm($r['orgao']); $dup = false;
      foreach (recentes(JANELA_DUPLICADA_MIN) as $x)
        if (strtolower((string)($x['email'] ?? '')) === $email && (($cnpj && soDigitos($x['cnpj'] ?? '') === $cnpj) || nomeNorm($x['orgao'] ?? '') === $nome)) { $dup = true; break; }
      if (!$dup) inserir($r);
    } finally { destravar(); }
    if ($dup) erro(409, 'Já recebemos uma inscrição deste órgão com este e-mail há poucos minutos. Não é preciso enviar de novo. Se quiser alterar algo, fale com a equipe Ibtech.');

    encerrarResposta(200, ['ok' => true, 'participantes' => count($ps), 'valorTotal' => $r['valorTotal'], 'emailConfirmacao' => emailAtivo()]);
    confirmarERegistrar($r);
    exit;
  }

  // ---------- painel ----------
  if ($caminho === '/admin/') { header('Location: ' . $base . '/admin', true, 301); exit; }
  if (strpos($caminho, '/admin') === 0) {
    admin();
    if ($caminho === '/admin' && $metodo === 'GET') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/admin.html'); exit; }
    if ($caminho === '/admin/api/inscricoes' && $metodo === 'GET') { responder(200, lista()); exit; }
    if ($caminho === '/admin/api/config' && $metodo === 'GET') {
      responder(200, ['emailAtivo' => emailAtivo(), 'faltando' => array_values(array_filter(['RESEND_API_KEY', 'EMAIL_FROM'], function ($k) { return cfg($k) === ''; })),
        'remetente' => cfg('EMAIL_FROM'), 'situacoes' => SITUACOES, 'formas' => FORMAS]);
      exit;
    }
    if (preg_match('#^/admin/api/inscricoes/([^/]+)$#', $caminho, $m) && $metodo === 'DELETE') {
      db()->prepare('DELETE FROM inscricoes WHERE id = ?')->execute([urldecode($m[1])]); responder(200, ['ok' => true]); exit;
    }
    if (preg_match('#^/admin/api/inscricoes/([^/]+)/reenviar-email$#', $caminho, $m) && $metodo === 'POST') {
      $r = obter(urldecode($m[1]));
      if (!$r) erro(404, 'Inscrição não encontrada.');
      if (!emailAtivo()) erro(400, 'E-mail desativado: preencha RESEND_API_KEY e EMAIL_FROM no config.php.');
      $st = confirmarERegistrar($r); $ok = $st['situacao'] === 'enviado';
      responder($ok ? 200 : 502, ['ok' => $ok, 'status' => $st, 'erro' => $st['erro'] ?? null]); exit;
    }
    if (preg_match('#^/admin/api/inscricoes/([^/]+)/financeiro$#', $caminho, $m) && $metodo === 'PUT') {
      $b = corpoJson();
      $vpBruto = $b['valorPago'] ?? 0;
      if ($vpBruto === '' || $vpBruto === null) $vpBruto = 0;
      $vp = is_numeric($vpBruto) ? round((float)$vpBruto * 100) / 100 : -1;
      $f = ['situacao' => txt($b['situacao'] ?? '', 20), 'forma' => txt($b['forma'] ?? '', 20), 'empenho' => txt($b['empenho'] ?? '', 60), 'notaFiscal' => txt($b['notaFiscal'] ?? '', 60),
        'dataPagamento' => txt($b['dataPagamento'] ?? '', 10), 'valorPago' => $vp, 'obs' => txt($b['obs'] ?? '', 500), 'atualizadoEm' => agoraIso()];
      if (!in_array($f['situacao'], SITUACOES, true)) erro(400, 'Situação inválida.');
      if (!in_array($f['forma'], FORMAS, true)) erro(400, 'Forma de pagamento inválida.');
      if ($vp < 0 || $vp > 1e6) erro(400, 'Valor pago inválido.');
      if ($f['dataPagamento'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['dataPagamento'])) erro(400, 'Data de pagamento inválida.');
      if (!setCampo(urldecode($m[1]), 'financeiro', $f)) erro(404, 'Inscrição não encontrada.');
      responder(200, ['ok' => true, 'financeiro' => $f]); exit;
    }
    // Importa inscrições de um backup (lista de {id, criada_em, dados} ou das próprias inscrições). Não sobrescreve as existentes.
    if ($caminho === '/admin/api/importar' && $metodo === 'POST') {
      $GLOBALS['CORPO_GRANDE'] = true;
      $novas = 0; $total = 0;
      foreach (corpoJson() as $item) {
        $d = is_array($item['dados'] ?? null) ? $item['dados'] : $item;
        if (!is_array($d) || empty($d['id']) || empty($d['criadaEm'])) continue;
        $total++; if (inserir($d)) $novas++;
      }
      responder(200, ['ok' => true, 'recebidas' => $total, 'novas' => $novas]); exit;
    }
    if ($caminho === '/admin/inscricoes.csv' && $metodo === 'GET') {
      $linhas = [['Recebida', 'Órgão', 'Tipo', 'CNPJ', 'Responsável', 'Cargo', 'E-mail', 'Telefone', 'Participantes', 'Qtd', 'Valor total (R$)', 'Notebook', 'Observações',
        'Situação', 'Forma de pagamento', 'Nº empenho', 'Nº nota fiscal', 'Data do pagamento', 'Valor pago (R$)', 'Saldo (R$)', 'Obs. financeiras']];
      foreach (lista() as $r) { $f = finDe($r); $linhas[] = [
        horaBrasilia($r['criadaEm']), $r['orgao'] ?? '', $r['tipo'] ?? '', $r['cnpj'] ?? '', $r['responsavel'] ?? '', $r['cargo'] ?? '', $r['email'] ?? '', $r['telefone'] ?? '',
        participantesTexto($r), count($r['participantes'] ?? []), reais($r['valorTotal'] ?? 0), $r['notebook'] ?? '', $r['observacoes'] ?? '',
        $f['situacao'], $f['forma'], $f['empenho'], $f['notaFiscal'], dataBR((string)$f['dataPagamento']), reais($f['valorPago']), reais(saldo($r, $f)), $f['obs']]; }
      enviarCsv('inscricoes-treinamento-ibtech.csv', $linhas); exit;
    }
    if ($caminho === '/admin/participantes.csv' && $metodo === 'GET') {
      $linhas = [['Nome completo', 'E-mail', 'Cargo', 'Órgão', 'CNPJ', 'Situação financeira']];
      foreach (array_reverse(lista()) as $r) { $f = finDe($r); if ($f['situacao'] === 'Cancelado') continue;
        foreach ($r['participantes'] ?? [] as $p) $linhas[] = [$p['nome'] ?? '', $p['email'] ?? '', $p['cargo'] ?? '', $r['orgao'] ?? '', $r['cnpj'] ?? '', $f['situacao']]; }
      enviarCsv('participantes-treinamento-ibtech.csv', $linhas); exit;
    }
    if ($caminho === '/admin/inscricoes.xlsx' && $metodo === 'GET') {
      $todas = lista(); $li = []; $lp = [];
      foreach ($todas as $r) {
        $f = finDe($r);
        $ts = strtotime($r['criadaEm']);
        $li[] = [$ts ? serialExcel($ts - 3 * 3600) : null, $r['orgao'] ?? '', $r['tipo'] ?? '', $r['cnpj'] ?? '', $r['responsavel'] ?? '', $r['cargo'] ?? '', $r['email'] ?? '', $r['telefone'] ?? '',
          participantesTexto($r), count($r['participantes'] ?? []), (float)($r['valorTotal'] ?? 0), $r['notebook'] ?? '', $r['observacoes'] ?? '',
          $f['situacao'], $f['forma'], $f['empenho'], $f['notaFiscal'], $f['dataPagamento'] ? serialExcel((int)strtotime($f['dataPagamento'] . 'T00:00:00Z')) : null,
          (float)$f['valorPago'], saldo($r, $f), $f['obs']];
      }
      foreach (array_reverse($todas) as $r) { $f = finDe($r); if ($f['situacao'] === 'Cancelado') continue;
        foreach ($r['participantes'] ?? [] as $p) $lp[] = [$p['nome'] ?? '', $p['email'] ?? '', $p['cargo'] ?? '', $r['orgao'] ?? '', $r['cnpj'] ?? '', $f['situacao']]; }
      $bin = xlsx([
        ['nome' => 'Inscrições', 'colunas' => [['Recebida', 18, 3], ['Órgão', 34, 0], ['Tipo', 14, 0], ['CNPJ', 20, 0], ['Responsável', 26, 0], ['Cargo', 20, 0], ['E-mail', 28, 0],
          ['Telefone', 16, 0], ['Participantes', 50, 0], ['Qtd', 6, 0], ['Valor total', 14, 2], ['Notebook', 10, 0], ['Observações', 30, 0], ['Situação', 12, 0],
          ['Forma de pagamento', 18, 0], ['Nº empenho', 14, 0], ['Nº nota fiscal', 14, 0], ['Data do pagamento', 16, 4], ['Valor pago', 14, 2], ['Saldo', 14, 2], ['Obs. financeiras', 30, 0]], 'linhas' => $li],
        ['nome' => 'Participantes', 'colunas' => [['Nome completo', 34, 0], ['E-mail', 30, 0], ['Cargo', 22, 0], ['Órgão', 34, 0], ['CNPJ', 20, 0], ['Situação financeira', 18, 0]], 'linhas' => $lp],
      ]);
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="inscricoes-treinamento-ibtech.xlsx"');
      echo $bin; exit;
    }
  }

  http_response_code(404); echo 'Página não encontrada.';
} catch (Throwable $e) {
  error_log((string)$e);
  if (!headers_sent()) responder(500, ['erro' => 'Erro interno. Tente novamente.']);
}
