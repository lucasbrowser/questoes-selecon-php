<?php
declare(strict_types=1);

/**
 * Leitura dos PDFs da Selecon (provas e gabaritos) usando `pdftotext` (poppler-utils).
 * PDFs escaneados (sem texto) são detectados e rejeitados com mensagem clara.
 */
final class Parser
{
    /** Normaliza ligaduras e aspas/travessões que alguns PDFs exportam como caracteres de controle (cp1252). */
    private static function limpa(string $t): string
    {
        $t = strtr($t, ['ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', "\xC2\xA0" => ' ', "\xE2\x80\x8B" => '',
            "\u{0085}" => '…', "\u{0091}" => '‘', "\u{0092}" => '’', "\u{0093}" => '“', "\u{0094}" => '”',
            "\u{0095}" => '•', "\u{0096}" => '–', "\u{0097}" => '—']);
        return (string)preg_replace('/[\x{0080}-\x{009F}\x{E000}-\x{F8FF}]/u', '', $t);
    }

    // ---------------------------------------------------------------- PDF -> texto
    /** @return string[] texto de cada página */
    public static function paginas(string $pdf, bool $layout = false): array
    {
        $cmd = escapeshellarg(PDFTOTEXT) . ($layout ? ' -layout' : '') . ' -enc UTF-8 '
             . escapeshellarg($pdf) . ' - 2>/dev/null';
        $out = shell_exec($cmd);
        if ($out === null || trim(str_replace("\f", '', $out)) === '') {
            throw new RuntimeException('Não foi possível extrair texto do PDF (arquivo escaneado? faça OCR antes: ocrmypdf).');
        }
        $out = self::limpa($out);
        $pags = explode("\f", $out);
        if (end($pags) === '' || trim((string)end($pags)) === '') {
            array_pop($pags);
        }
        return $pags;
    }

    /**
     * Linhas visuais de cada página, em ordem de leitura de 2 colunas (esquerda -> direita),
     * usando as coordenadas do `pdftotext -bbox-layout`. Cabeçalho/rodapé são cortados por posição.
     * @return string[][]
     */
    public static function paginasLinhas(string $pdf): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bbox');
        $cmd = escapeshellarg(PDFTOTEXT) . ' -bbox-layout -enc UTF-8 ' . escapeshellarg($pdf) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
        shell_exec($cmd);
        $xml = (string)@file_get_contents($tmp);
        @unlink($tmp);
        if (trim($xml) === '') {
            throw new RuntimeException('Não foi possível extrair texto do PDF.');
        }
        $xml = self::limpa($xml);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $xml);
        $paginas = [];
        foreach ($dom->getElementsByTagName('page') as $pg) {
            $w = (float)$pg->getAttribute('width');
            $hgt = (float)$pg->getAttribute('height');
            $cols = [0 => [], 1 => []];
            foreach ($pg->getElementsByTagName('line') as $ln) {
                $y = (float)$ln->getAttribute('ymin');
                $x0 = (float)$ln->getAttribute('xmin');
                $x1 = (float)$ln->getAttribute('xmax');
                if ($y < 46 || $y > $hgt - 40) {
                    continue; // cabeçalho / rodapé
                }
                $words = [];
                foreach ($ln->getElementsByTagName('word') as $wd) {
                    $words[] = $wd->textContent;
                }
                $cols[(($x0 + $x1) / 2 < $w / 2) ? 0 : 1][] = ['y' => $y, 'x' => $x0, 't' => implode(' ', $words)];
            }
            $out = [];
            foreach ($cols as $c) {
                usort($c, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);
                // une fragmentos na mesma linha visual ("A)" + texto)
                $vis = [];
                foreach ($c as $f) {
                    $n = count($vis);
                    if ($n && abs($vis[$n - 1]['y'] - $f['y']) < 3) {
                        $vis[$n - 1]['t'] .= ' ' . $f['t'];
                    } else {
                        $vis[] = ['y' => $f['y'], 't' => $f['t']];
                    }
                }
                foreach ($vis as $v) {
                    $out[] = trim($v['t']);
                }
            }
            $paginas[] = $out;
        }
        if (!array_filter($paginas)) {
            throw new RuntimeException('PDF sem camada de texto.');
        }
        return $paginas;
    }

    /**
     * OCR para PDFs sem camada de texto (escaneados ou com texto em curvas).
     * Requer pdftoppm + tesseract com o idioma "por". Lento (alguns segundos por página).
     * @return array{0: string[], 1: string[][]} [texto por página, linhas por página]
     */
    public static function ocr(string $pdf, ?array $somente = null): array
    {
        if (!trim((string)shell_exec('command -v tesseract')) || !trim((string)shell_exec('command -v pdftoppm'))) {
            throw new RuntimeException('PDF sem texto e OCR indisponível (instale tesseract-ocr + tesseract-ocr-por e poppler-utils).');
        }
        $info = (string)shell_exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null');
        preg_match('/Pages:\s+(\d+)/', $info, $mp);
        preg_match('/Page size:\s+([\d.]+)\s*x\s*([\d.]+)/', $info, $ms);
        $n = (int)($mp[1] ?? 0);
        $dpi = 200;
        $wpx = (int)round(((float)($ms[1] ?? 595)) * $dpi / 72);
        $hpx = (int)round(((float)($ms[2] ?? 842)) * $dpi / 72);
        $dir = sys_get_temp_dir() . '/ocr' . bin2hex(random_bytes(4));
        mkdir($dir);
        $lang = trim((string)shell_exec('tesseract --list-langs 2>/dev/null | grep -x por')) ? 'por' : 'eng';
        $textos = [];
        $linhas = [];
        foreach ($somente ?? range(1, $n) as $p) {
            $ls = [];
            foreach ([0, 1] as $c) {
                $img = "$dir/p{$p}c{$c}";
                $y0 = (int)($hpx * 0.055);
                $cmd = sprintf('pdftoppm -r %d -f %d -l %d -x %d -y %d -W %d -H %d -png -singlefile %s %s 2>/dev/null',
                    $dpi, $p, $p, $c * intdiv($wpx, 2), $y0, intdiv($wpx, 2), (int)($hpx * 0.90), escapeshellarg($pdf), escapeshellarg($img));
                shell_exec($cmd);
                $txt = (string)shell_exec(sprintf('tesseract %s - -l %s --psm 6 2>/dev/null', escapeshellarg($img . '.png'), $lang));
                @unlink($img . '.png');
                foreach (preg_split('/\R/u', $txt) as $l) {
                    if (trim($l) !== '') {
                        // OCR separa dígitos do número da questão ("1 6." -> "16.")
                        $l = (string)preg_replace('/^(\d)\s(\d)\s*([.)])/u', '$1$2$3', trim($l));
                        // "l."/"ll."/"2l." (letra l no lugar do dígito 1): só quando for "l" minúsculo ou houver dígito
                        if (preg_match('/^([0-9lI|!]{1,3})\s?([.)])\s+\S/u', $l, $mm)
                            && ($mm[1] === 'l' || preg_match('/\d/', $mm[1]) || preg_match('/^[lI|!]{2}$/', $mm[1]))) {
                            $l = strtr($mm[1], ['l' => '1', 'I' => '1', '|' => '1', '!' => '1']) . substr($l, strlen($mm[1]));
                        }
                        $ls[] = $l;
                    }
                }
            }
            $linhas[$p - 1] = $ls;
            $textos[$p - 1] = implode("\n", $ls);
        }
        if ($n > 0 && $somente === null) { // capa: página inteira, para preservar a ordem de leitura
            $img = "$dir/capa";
            shell_exec(sprintf('pdftoppm -r %d -f 1 -l 1 -png -singlefile %s %s 2>/dev/null', $dpi, escapeshellarg($pdf), escapeshellarg($img)));
            $textos[0] = (string)shell_exec(sprintf('tesseract %s - -l %s --psm 4 2>/dev/null', escapeshellarg($img . '.png'), $lang));
        }
        @array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
        return [$textos, $linhas];
    }

    /** OCR de página inteira (para tabelas de gabarito escaneadas). */
    public static function ocrTexto(string $pdf): string
    {
        if (!trim((string)shell_exec('command -v tesseract'))) {
            throw new RuntimeException('PDF sem texto e OCR indisponível.');
        }
        $dir = sys_get_temp_dir() . '/ocrg' . bin2hex(random_bytes(4));
        mkdir($dir);
        shell_exec(sprintf('pdftoppm -r 300 -gray -png %s %s/p 2>/dev/null', escapeshellarg($pdf), $dir));
        $lang = trim((string)shell_exec('tesseract --list-langs 2>/dev/null | grep -x por')) ? 'por' : 'eng';
        $out = '';
        $imgs = glob("$dir/p*.png") ?: [];
        sort($imgs);
        foreach ($imgs as $img) {
            $out .= (string)shell_exec(sprintf('tesseract %s - -l %s --psm 4 2>/dev/null', escapeshellarg($img), $lang)) . "\n";
            @unlink($img);
        }
        @rmdir($dir);
        // o OCR costuma ler "C" como "€"/"©" dentro das células do gabarito
        return (string)preg_replace('/(\d{1,3})\s*:\s*[€©¢Ç]/u', '$1: C', $out);
    }

    // ---------------------------------------------------------------- Gabaritos
    /**
     * Devolve blocos: [['chave'=>'401','titulo'=>'ADVOGADO','versao'=>'','respostas'=>[1=>'A',...]], ...]
     * Suporta dois formatos da Selecon:
     *  (1) "01: A  02: D ..." precedido por "401 - CARGO" (e opcional "PROVA A");
     *  (2) linha de números seguida de linha de letras (tabelas por disciplina).
     */
    public static function gabarito(string $pdf): array
    {
        try {
            $texto = strtolower(pathinfo($pdf, PATHINFO_EXTENSION)) === 'txt'
                ? (string)file_get_contents($pdf)                 // gabarito digitado: "401 - CARGO" + "01: A 02: B ..."
                : implode("\n", self::paginas($pdf, true));
        } catch (RuntimeException $e) {
            $texto = self::ocrTexto($pdf); // gabarito escaneado
        }
        $linhas = preg_split('/\R/u', $texto);

        $blocos = [];
        $atual = ['chave' => '', 'titulo' => '', 'versao' => '', 'respostas' => []];
        $pend = null; // linha de números aguardando linha de letras
        $temPares = (bool)preg_match('/\b\d{1,3}\s*:\s*[A-EX*]\b/u', $texto);

        $fecha = function () use (&$blocos, &$atual) {
            if ($atual['respostas']) {
                $blocos[] = $atual;
            }
        };

        foreach ($linhas as $ln) {
            $t = trim($ln);
            if ($t === '') {
                continue;
            }
            // título "401 - ADVOGADO"
            if (preg_match('/^(\d{3})\s*[-–]\s*(.+)$/u', $t, $m)) {
                $fecha();
                $atual = ['chave' => $m[1], 'titulo' => trim($m[2]), 'versao' => '', 'respostas' => []];
                continue;
            }
            if (preg_match('/^PROVA\s+([A-D])\b/u', $t, $m) && !$atual['respostas']) {
                $atual['versao'] = $m[1];
                continue;
            }
            if ($temPares) {
                if (preg_match_all('/\b(\d{1,3})\s*:\s*([A-EX*])\b/u', $t, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $p) {
                        $atual['respostas'][(int)$p[1]] = $p[2];
                    }
                }
                continue;
            }
            // formato 2: linhas de números / letras
            $tok = preg_split('/\s+/u', $t);
            $nums = array_values(array_filter($tok, fn($x) => preg_match('/^\d{1,3}$/', $x)));
            $lets = array_values(array_filter($tok, fn($x) => preg_match('/^[A-EX*]$/u', $x)));
            if (count($nums) >= 2 && !$lets) {
                $pend = array_map('intval', $nums);
            } elseif (count($lets) >= 2 && !$nums && $pend && count($lets) === count($pend)) {
                foreach ($pend as $i => $n) {
                    $atual['respostas'][$n] = $lets[$i];
                }
                $pend = null;
            } elseif (count($lets) === 1 && count($nums) === 1) {
                // linha "1 B" isolada — ignora
            }
        }
        $fecha();
        if (!$blocos) {
            throw new RuntimeException('Nenhum gabarito reconhecido no PDF.');
        }
        return $blocos;
    }

    // ---------------------------------------------------------------- Provas
    /**
     * Lê um PDF de prova (pode conter vários cadernos/cargos) e devolve uma lista de cadernos:
     * ['meta'=>[...], 'questoes'=>[ ['numero','disciplina','texto_apoio','enunciado','alternativas'=>[A=>..],'revisar'] ]]
     */
    public static function prova(string $pdf): array
    {
        try {
            $pags = self::paginas($pdf, false);   // texto corrido (capas)
            $lin = self::paginasLinhas($pdf);     // linhas por coluna (questões)
            // PDFs mistos: páginas sem texto (escaneadas) passam por OCR
            $vazias = [];
            foreach ($lin as $i => $ls) {
                if (count($ls) < 5) {
                    $vazias[] = $i + 1;
                }
            }
            if ($vazias && count($vazias) < count($lin)) {
                [$t2, $l2] = self::ocr($pdf, $vazias);
                foreach ($l2 as $i => $ls) {
                    $lin[$i] = $ls;
                    $pags[$i] = $t2[$i];
                }
            }
        } catch (RuntimeException $e) {
            [$pags, $lin] = self::ocr($pdf);      // PDF escaneado/sem texto -> OCR (tesseract)
        }

        // 1) Divide em cadernos pela página de instruções.
        $grupos = [];
        $cur = null;
        foreach ($pags as $i => $p) {
            if (preg_match('/Leia\s+atentamente\s+as\s+instru/iu', $p)) {
                $cur = count($grupos);
                $grupos[$cur] = ['capa' => $p, 'linhas' => []];
            }
            if ($cur === null) {
                $grupos[0] = ['capa' => $p, 'linhas' => []];
                $cur = 0;
            }
            $grupos[$cur]['linhas'][] = $lin[$i] ?? [];
        }

        $cadernos = [];
        foreach ($grupos as $g) {
            $meta = self::metaCapa($g['capa']);
            $total = preg_match('/com\s+(\d+)\s*\([^)]*\)\s*quest/iu', $g['capa'], $tm) ? (int)$tm[1] : 100;
            $qs = self::questoes($g['linhas'], $total);
            if (count($qs) >= 1) {
                $cadernos[] = ['meta' => $meta, 'questoes' => $qs];
            }
        }
        return $cadernos;
    }

    /** Extrai órgão, edital, cargo, código, versão e ano da capa. */
    private static function metaCapa(string $capa): array
    {
        $ls = array_values(array_filter(array_map('trim', preg_split('/\R/u', $capa)), fn($x) => $x !== ''));
        $m = ['codigo' => '', 'versao' => '', 'orgao' => '', 'edital' => '', 'cargo' => '', 'nivel' => '', 'ano' => null];

        // código (ex.: "401") e versão (ex.: "A" + "PROVA") nas primeiras linhas
        foreach (array_slice($ls, 0, 6) as $k => $l) {
            if (!$m['codigo'] && preg_match('/^\d{3}$/', $l)) {
                $m['codigo'] = $l;
            }
            if (!$m['versao'] && preg_match('/^[A-D]$/', $l) && isset($ls[$k + 1]) && stripos($ls[$k + 1], 'PROVA') === 0) {
                $m['versao'] = $l;
            }
        }

        $idxEdital = null;
        $idxDur = null;
        foreach ($ls as $k => $l) {
            if ($idxEdital === null && preg_match('/EDITAL|^N[º°o]\s*\d/iu', $l)) {
                $idxEdital = $k;
                $m['edital'] = trim(preg_replace('/^.*?EDITAL\s*[-–]?\s*/iu', '', $l));
            }
            if (preg_match('/Dura[çc][ãa]o/iu', $l) || preg_match('/Data:\s*\d/iu', $l)) {
                $idxDur = $k;
                break;
            }
        }
        // órgão: linhas com palavras-chave antes do edital
        $org = [];
        foreach ($ls as $k => $l) {
            if ($idxEdital !== null && $k >= $idxEdital) {
                break;
            }
            if (preg_match('/(C[ÂA]MARA|EMPRESA|SECRETARIA|MINIST[ÉE]RIO|PREFEITURA|TRIBUNAL|INSTITUTO|FUNDA[ÇC][ÃA]O|ASSEMBLEIA|AUTARQUIA|CONSELHO)/iu', $l)
                && !preg_match('/^(PROCESSO|CONCURSO)/iu', $l)) {
                $org[] = $l;
            }
        }
        $m['orgao'] = trim(implode(' ', array_unique($org)));
        // cargo: linhas entre o edital (ou nº) e a duração
        $ini = $idxEdital !== null ? $idxEdital + 1 : 0;
        $fim = $idxDur ?? min(count($ls), $ini + 4);
        $cg = [];
        for ($k = $ini; $k < $fim; $k++) {
            if (preg_match('/^(T[ÉE]CNICO\s+N[ÍI]VEL|N[ÍI]VEL)/iu', $ls[$k])) {
                $m['nivel'] = $ls[$k];
            } elseif (!preg_match('/^(PROCESSO|CONCURSO|ESTE\s+CADERNO)/iu', $ls[$k]) && $ls[$k] !== $m['codigo']) {
                $cg[] = $ls[$k];
            }
        }
        $m['cargo'] = trim(implode(' ', $cg));
        $all = implode(' ', array_slice($ls, 0, 12));
        if (preg_match('/\/\s*(20\d\d)/', $all, $y) || preg_match('/(20\d\d)/', $all, $y)) {
            $m['ano'] = (int)$y[1];
        }
        return $m;
    }

    /** Quebra de linhas visuais -> parágrafos. */
    private static function junta(array $linhas): string
    {
        $out = '';
        foreach ($linhas as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            if ($out === '') {
                $out = $l;
            } elseif (preg_match('/^(\(?[IVX]{1,4}\)?\s*[-–.)]|[•\-–]\s|\([a-eA-E]\)|\d+\s*[.)]\s)/u', $l)
                || preg_match('/[:;]\s*$/u', $out) && mb_strlen($out) < 80) {
                $out .= "\n" . $l;
            } elseif (preg_match('/-$/', $out) && preg_match('/^\p{Ll}/u', $l)) {
                $out = substr($out, 0, -1) . $l;
            } else {
                $out .= ' ' . $l;
            }
        }
        return $out;
    }

    private static function questoes(array $paginas, int $total = 100): array
    {
        // remove cabeçalhos repetidos (linhas presentes na maioria das páginas) e numeração de página
        $cont = [];
        foreach ($paginas as $ls) {
            foreach (array_unique($ls) as $l) {
                $cont[$l] = ($cont[$l] ?? 0) + 1;
            }
        }
        $lixo = [];
        $minPag = max(3, (int)ceil(count($paginas) * 0.6));
        foreach ($cont as $l => $c) {
            if ($c >= $minPag && mb_strlen((string)$l) > 8 && !preg_match('/^(\d{1,3}\s*[.)]|[A-E]\))/u', (string)$l)) {
                $lixo[$l] = true;
            }
        }
        $todas = [];
        foreach ($paginas as $i => $ls) {
            $n = count($ls);
            foreach ($ls as $k => $t) {
                if (isset($lixo[$t])) {
                    continue;
                }
                if (preg_match('/^\d{1,3}$/', $t) && ($k === 0 || $k === $n - 1 || $k === intdiv($n, 2) - 1)) {
                    continue; // número de página
                }
                $todas[] = $t;
            }
        }

        $qs = [];
        $q = null;
        $vistas = [];
        $maxVista = 0;
        $discGeral = '';
        $discEsp = '';
        $orfas = [];      // linhas de alternativas "soltas"
        $labelsVazios = 0;
        $alt = null;
        $apoioAtivo = null; // ['linhas'=>[], 'de'=>n, 'ate'=>m]
        $apoios = [];       // lista de apoios com faixa
        $fim = false;

        $fechaQ = function () use (&$q, &$qs, &$orfas, &$labelsVazios, &$alt) {
            if ($q === null) {
                return;
            }
            $q['revisar'] = false;
            if ($orfas) {
                $q = self::distribuiOrfas($q, $orfas, $labelsVazios);
            }
            $q['enunciado'] = self::junta($q['_en']);
            foreach ($q['alternativas'] as $L => $linhas) {
                $q['alternativas'][$L] = is_array($linhas) ? self::junta($linhas) : $linhas;
            }
            unset($q['_en']);
            $n = count($q['alternativas']);
            if ($n < 4 || $n > 5 || in_array('', $q['alternativas'], true)) {
                $q['revisar'] = true;
            }
            $qs[] = $q;
            $q = null;
            $orfas = [];
            $labelsVazios = 0;
            $alt = null;
        };

        foreach ($todas as $t) {
            if ($fim) {
                break;
            }
            if ($t === '') {
                continue;
            }
            // fim da parte objetiva
            if (preg_match('/^(PROVA\s+DISCURSIVA|RASCUNHO|FOLHA\s+DE\s+RESPOSTA)/iu', $t) && count($qs) + ($q ? 1 : 0) > 0) {
                $fechaQ();
                $fim = true;
                continue;
            }
            // início de questão (número esperado)
            $menor = 1;
            while (isset($vistas[$menor])) {
                $menor++;
            }
            if (preg_match('/^(\d{1,3})\s*[.)]\s+(\S.*)$/u', $t, $m) && !isset($vistas[(int)$m[1]])
                && (int)$m[1] >= $menor && (int)$m[1] <= min($total, max($maxVista + 3, $menor + 6))) {
                $fechaQ();
                if ($apoioAtivo) {
                    $apoios[] = $apoioAtivo;
                    $apoioAtivo = null;
                }
                $vistas[(int)$m[1]] = true;
                $maxVista = max($maxVista, (int)$m[1]);
                $q = ['numero' => (int)$m[1], 'disciplina' => $discEsp ?: $discGeral, 'texto_apoio' => null,
                      '_en' => [$m[2]], 'alternativas' => []];
                continue;
            }
            // texto de apoio "Leia o texto a seguir para responder às questões de 1 a 4"
            if (preg_match('/^(Leia|Considere|Observe|Com\s+base|Analise|Utilize)\b.*\bquest(?:ões|ão)\b\s*(?:de\s*)?(\d{1,3})\s*(?:a|e|à|ao)\s*(\d{1,3})/iu', $t, $m)
                && (int)$m[2] >= $menor - 1) {
                $fechaQ();
                if ($apoioAtivo) {
                    $apoios[] = $apoioAtivo;
                }
                $apoioAtivo = ['linhas' => [$t], 'de' => (int)$m[2], 'ate' => (int)$m[3]];
                continue;
            }
            // título de disciplina
            if (self::ehTitulo($t) && ($q === null || $q['alternativas'] || $orfas)) {
                {
                    $fechaQ();
                    if ($apoioAtivo) {
                        $apoios[] = $apoioAtivo;
                        $apoioAtivo = null;
                    }
                    if (preg_match('/^CONHECIMENTOS\s+(B[ÁA]SICOS|GERAIS|ESPEC[ÍI]FICOS)$/u', $t)) {
                        $discGeral = self::titulo($t);
                        $discEsp = '';
                    } else {
                        $discEsp = self::titulo($t);
                    }
                    continue;
                }
            }
            if ($apoioAtivo && $q === null) {
                $apoioAtivo['linhas'][] = $t;
                continue;
            }
            if ($q === null) {
                continue; // lixo antes da 1ª questão (instruções)
            }
            // alternativa
            if (preg_match('/^([A-E])\s*\)\s*(.*)$/u', $t, $m)) {
                $L = $m[1];
                if ($m[2] === '') {
                    $labelsVazios++;
                    $q['alternativas'][$L] = [];
                    $alt = null;
                } else {
                    $q['alternativas'][$L] = [$m[2]];
                    $alt = $L;
                }
                continue;
            }
            if ($labelsVazios > 0 && !$alt) {
                $orfas[] = $t;
            } elseif ($alt) {
                $q['alternativas'][$alt][] = $t;
            } else {
                $q['_en'][] = $t;
            }
        }
        $fechaQ();
        if ($apoioAtivo) {
            $apoios[] = $apoioAtivo;
        }

        usort($qs, fn($a, $b) => $a['numero'] <=> $b['numero']);
        // aplica textos de apoio
        foreach ($apoios as $a) {
            $txt = self::junta(array_slice($a['linhas'], 1));
            $cab = $a['linhas'][0];
            foreach ($qs as &$qq) {
                if ($qq['numero'] >= $a['de'] && $qq['numero'] <= $a['ate']) {
                    $qq['texto_apoio'] = $cab . "\n" . $txt;
                }
            }
            unset($qq);
        }
        return $qs;
    }

    private static function distribuiOrfas(array $q, array $linhas, int $k): array
    {
        $letras = array_keys($q['alternativas']);
        $letras = array_slice($letras, -$k);
        $n = count($linhas);
        if ($n === $k) {
            $grupos = array_map(fn($l) => [$l], $linhas);
        } elseif ($n > $k && $k > 1) {
            $max = max(array_map('mb_strlen', $linhas));
            $sc = [];
            for ($i = 1; $i < $n; $i++) {
                $s = (1 - mb_strlen($linhas[$i - 1]) / max(1, $max)) + (preg_match('/^\p{Lu}|^\d/u', $linhas[$i]) ? 1 : 0);
                $sc[$i] = $s;
            }
            arsort($sc);
            $cortes = array_slice(array_keys($sc), 0, $k - 1);
            sort($cortes);
            $grupos = [];
            $ini = 0;
            foreach ($cortes as $c) {
                $grupos[] = array_slice($linhas, $ini, $c - $ini);
                $ini = $c;
            }
            $grupos[] = array_slice($linhas, $ini);
            $q['revisar'] = true;
        } else {
            $grupos = [$linhas];
            $q['revisar'] = true;
        }
        foreach ($letras as $i => $L) {
            $q['alternativas'][$L] = $grupos[$i] ?? [];
        }
        return $q;
    }

    private static function ehTitulo(string $t): bool
    {
        if (mb_strlen($t) < 6 || mb_strlen($t) > 80 || !preg_match('/\p{L}/u', $t)) {
            return false;
        }
        if (preg_match('/[a-zà-ú]/u', $t)) {
            return false;
        }
        if (preg_match('/^[A-E]\)/', $t) || preg_match('/\d{3,}/', $t)) {
            return false;
        }
        $letras = preg_match_all('/\p{L}/u', $t);
        $palavras = preg_split('/[\s,\/-]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        $longa = false;
        foreach ($palavras as $p) {
            if (mb_strlen($p) >= 4) {
                $longa = true;
            }
        }
        return $longa && $letras >= 8;
    }

    private static function semAcento(string $s): string
    {
        return strtr($s, 'ÁÀÂÃÉÊÍÓÔÕÚÇáàâãéêíóôõúç', 'AAAAEEIOOOUCaaaaeeiooouc');
    }

    public static function titulo(string $s): string
    {
        $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
        return preg_replace_callback('/\s(De|Da|Do|Das|Dos|E|Em|Na|No|A|O)\s/u', fn($m) => ' ' . mb_strtolower(trim($m[1])) . ' ', $s);
    }

    // ---------------------------------------------------------------- Casamento prova x gabarito
    public static function normaliza(string $s): string
    {
        $s = mb_strtolower(self::semAcento($s));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** Escolhe o bloco de gabarito que corresponde ao caderno. */
    public static function casaGabarito(array $meta, array $blocos): ?array
    {
        if (count($blocos) === 1) {
            return $blocos[0];
        }
        $cod = $meta['codigo'];
        $ver = $meta['versao'];
        if ($cod !== '') {
            foreach ($blocos as $b) {
                if ($b['chave'] === $cod && ($b['versao'] === $ver || $b['versao'] === '' || $ver === '')) {
                    return $b;
                }
            }
        }
        $nc = self::normaliza($meta['cargo']);
        foreach ($blocos as $b) {
            $nb = self::normaliza($b['titulo']);
            if ($nb !== '' && $nc !== '' && ($nb === $nc || str_contains($nc, $nb) || str_contains($nb, $nc))) {
                return $b;
            }
        }
        return null;
    }
}
