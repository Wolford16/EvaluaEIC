-- EvaluaEIC. Importa este archivo sobre la base existente seleccionada en phpMyAdmin.
-- Haz un respaldo antes de aplicarlo. Las actualizaciones son acumulativas.

-- Cursos y exámenes reutilizables. Los materiales anteriores quedan sin curso y privados.
CREATE TABLE IF NOT EXISTS cursos (
 id INTEGER PRIMARY KEY AUTO_INCREMENT,
 nombre VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documentos' AND COLUMN_NAME = 'curso_id'),
 'DO 0',
 'ALTER TABLE documentos ADD COLUMN curso_id INTEGER NULL,
    ADD CONSTRAINT documentos_curso_fk FOREIGN KEY(curso_id) REFERENCES cursos(id)'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documentos' AND COLUMN_NAME = 'compartido'),
 'DO 0',
 'ALTER TABLE documentos ADD COLUMN compartido TINYINT NOT NULL DEFAULT 0'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

-- Exámenes manuales y preguntas de distintos tipos.
SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documentos' AND COLUMN_NAME = 'token_manual'),
 'DO 0',
 'ALTER TABLE documentos MODIFY nombre_archivo VARCHAR(255) NULL, MODIFY ruta_archivo VARCHAR(255) NULL,
    ADD COLUMN token_manual CHAR(48) DEFAULT NULL, ADD UNIQUE (subido_por_id, token_manual)'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preguntas' AND COLUMN_NAME = 'tipo'),
 'DO 0',
 'ALTER TABLE preguntas MODIFY correcta INTEGER NULL, ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT ''opcion_multiple'',
    ADD COLUMN respuestas_aceptadas_json TEXT NULL'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'respuestas_participantes' AND COLUMN_NAME = 'texto'),
 'DO 0',
 'ALTER TABLE respuestas_participantes ADD COLUMN texto VARCHAR(150) NULL'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

-- Aplicaciones de entrada y salida e identidad personal entre las dos salas.
SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salas_evaluacion' AND COLUMN_NAME = 'entrada_id'),
 'DO 0',
 'ALTER TABLE salas_evaluacion ADD COLUMN es_entrada TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN entrada_id INTEGER NULL UNIQUE,
    ADD CONSTRAINT salas_entrada_fk FOREIGN KEY(entrada_id) REFERENCES salas_evaluacion(id)'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;

SET @evaluaeic_sql = IF(
 EXISTS (SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes' AND COLUMN_NAME = 'participante_entrada_id'),
 'DO 0',
 'ALTER TABLE participantes ADD COLUMN codigo_seguimiento_hash CHAR(64) NULL, ADD COLUMN participante_entrada_id INTEGER NULL,
    ADD UNIQUE(sala_id, codigo_seguimiento_hash), ADD UNIQUE(sala_id, participante_entrada_id),
    ADD CONSTRAINT participantes_entrada_fk FOREIGN KEY(participante_entrada_id) REFERENCES participantes(id)'
);
PREPARE evaluaeic_cambio FROM @evaluaeic_sql;
EXECUTE evaluaeic_cambio;
DEALLOCATE PREPARE evaluaeic_cambio;
