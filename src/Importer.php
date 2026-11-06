<?php
declare(strict_types=1);

require_once __DIR__ . '/Parser.php';

final class Importer
{
    /**
     * Lê provas + gabaritos e devolve a lista de cadernos prontos para revisão/gravação.
     * @param string[] $provas    caminhos de PDFs de prova
     * @param string[] $gabaritos caminhos de PDFs de gabarito
     */
    public static function analisa(array $provas, array $gabaritos): array
    {
        $blocos = [];
        foreach ($gabaritos as $g) {
            $blocos = array_merge($blocos, Parser::gabarito($g));
        }
        $res = [];
        foreach ($provas as $p) {
            foreach (Parser::prova($p) as $cad) {
                $meta = $cad['meta'];
                $meta['arquivo'] = basename($p);
                $gab = $blocos ? Parser::casaGabarito($meta, $blocos) : null;
                $resp = $gab['respostas'] ?? [];
                $nums = array_column($cad['questoes'], 'numero');
                $max = $nums ? max($nums) : 0;
                $cad['meta'] = $meta;
                $cad['gabarito_ok'] = (bool)$gab;
                $cad['gabarito'] = $resp;
                $cad['faltando'] = array_values(array_diff(range(1, max($max, $resp ? max(array_keys($resp)) : 0)), $nums));
                $res[] = $cad;
            }
        }
        return $res;
    }

    /** Grava cadernos no banco. Retorna [provas, questoes]. */
    public static function grava(PDO $db, array $cadernos): array
    {
        $nProvas = 0;
        $nQuest = 0;
        $db->beginTransaction();
        try {
            foreach ($cadernos as $cad) {
                $m = $cad['meta'];
                if (empty($m['cargo']) || empty($m['orgao'])) {
                    continue;
                }
                $st = $db->prepare('INSERT INTO concursos (orgao, edital, ano) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), ano = COALESCE(VALUES(ano), ano)');
                $st->execute([$m['orgao'], $m['edital'] ?? '', $m['ano'] ?: null]);
                $cid = (int)$db->lastInsertId();

                $st = $db->prepare('INSERT INTO provas (concurso_id, cargo, codigo, nivel, versao) VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), codigo = VALUES(codigo), nivel = VALUES(nivel)');
                $st->execute([$cid, $m['cargo'], $m['codigo'] ?? '', $m['nivel'] ?? '', $m['versao'] ?? '']);
                $pid = (int)$db->lastInsertId();
                $nProvas++;

                $stD = $db->prepare('INSERT INTO disciplinas (nome) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
                $stQ = $db->prepare('INSERT INTO questoes (prova_id, numero, disciplina_id, texto_apoio, enunciado, gabarito, anulada, revisar)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), disciplina_id = VALUES(disciplina_id),
                      texto_apoio = VALUES(texto_apoio), enunciado = VALUES(enunciado), gabarito = VALUES(gabarito),
                      anulada = VALUES(anulada), revisar = VALUES(revisar)');
                $stA = $db->prepare('INSERT INTO alternativas (questao_id, letra, texto) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE texto = VALUES(texto)');
                $stDel = $db->prepare('DELETE FROM alternativas WHERE questao_id = ?');

                foreach ($cad['questoes'] as $q) {
                    $did = null;
                    if (!empty($q['disciplina'])) {
                        $stD->execute([$q['disciplina']]);
                        $did = (int)$db->lastInsertId();
                    }
                    $g = $cad['gabarito'][$q['numero']] ?? null;
                    $anulada = in_array($g, ['X', '*'], true);
                    $letra = ($g && !$anulada) ? $g : null;
                    $revisar = !empty($q['revisar']) || ($g === null);
                    $stQ->execute([$pid, $q['numero'], $did, $q['texto_apoio'] ?: null, $q['enunciado'],
                        $letra, $anulada ? 1 : 0, $revisar ? 1 : 0]);
                    $qid = (int)$db->lastInsertId();
                    $stDel->execute([$qid]);
                    foreach ($q['alternativas'] as $L => $txt) {
                        $stA->execute([$qid, $L, $txt]);
                    }
                    $nQuest++;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return [$nProvas, $nQuest];
    }
}
