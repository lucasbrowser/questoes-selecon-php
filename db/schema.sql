-- Banco de Questões Selecon — esquema MySQL 5.7+/8.x e MariaDB 10.3+ (InnoDB, utf8mb4)
-- Criar o banco antes (ou descomente):
-- CREATE DATABASE bancoselecon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE bancoselecon;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
    id          INT NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(200) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    senha_hash  VARCHAR(255) NOT NULL,
    is_admin    TINYINT(1) NOT NULL DEFAULT 0,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY usuarios_email_uk (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disciplinas (
    id    INT NOT NULL AUTO_INCREMENT,
    nome  VARCHAR(200) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY disciplinas_nome_uk (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS concursos (
    id      INT NOT NULL AUTO_INCREMENT,
    banca   VARCHAR(50)  NOT NULL DEFAULT 'Selecon',
    orgao   VARCHAR(300) NOT NULL,
    edital  VARCHAR(200) NOT NULL DEFAULT '',
    ano     INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY concursos_orgao_edital_uk (orgao, edital)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provas (
    id           INT NOT NULL AUTO_INCREMENT,
    concurso_id  INT NOT NULL,
    cargo        VARCHAR(300) NOT NULL,
    codigo       VARCHAR(20)  NOT NULL DEFAULT '',
    nivel        VARCHAR(200) NOT NULL DEFAULT '',
    versao       VARCHAR(10)  NOT NULL DEFAULT '',   -- ex.: 'A' (Prova A)
    PRIMARY KEY (id),
    UNIQUE KEY provas_concurso_cargo_versao_uk (concurso_id, cargo, versao),
    CONSTRAINT provas_concurso_fk FOREIGN KEY (concurso_id) REFERENCES concursos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questoes (
    id             INT NOT NULL AUTO_INCREMENT,
    prova_id       INT NOT NULL,
    numero         INT NOT NULL,
    disciplina_id  INT NULL,
    texto_apoio    MEDIUMTEXT NULL,
    enunciado      MEDIUMTEXT NOT NULL,
    gabarito       CHAR(1) NULL,                 -- A..E; NULL = sem gabarito
    anulada        TINYINT(1) NOT NULL DEFAULT 0,
    revisar        TINYINT(1) NOT NULL DEFAULT 0, -- importação incerta, conferir
    comentario     MEDIUMTEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY questoes_prova_numero_uk (prova_id, numero),
    KEY questoes_disciplina_idx (disciplina_id),
    FULLTEXT KEY questoes_busca_ft (enunciado),
    CONSTRAINT questoes_prova_fk FOREIGN KEY (prova_id) REFERENCES provas(id) ON DELETE CASCADE,
    CONSTRAINT questoes_disciplina_fk FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alternativas (
    questao_id  INT NOT NULL,
    letra       CHAR(1) NOT NULL,
    texto       MEDIUMTEXT NOT NULL,
    PRIMARY KEY (questao_id, letra),
    CONSTRAINT alternativas_questao_fk FOREIGN KEY (questao_id) REFERENCES questoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS respostas (
    id             BIGINT NOT NULL AUTO_INCREMENT,
    usuario_id     INT NOT NULL,
    questao_id     INT NOT NULL,
    letra          CHAR(1) NOT NULL,
    correta        TINYINT(1) NOT NULL,
    respondida_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY respostas_usuario_idx (usuario_id, questao_id),
    CONSTRAINT respostas_usuario_fk FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT respostas_questao_fk FOREIGN KEY (questao_id) REFERENCES questoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
