-- EvaluaEIC. Selecciona la base asignada antes de importar en phpMyAdmin.
CREATE TABLE IF NOT EXISTS usuarios (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(120) NOT NULL,
 usuario VARCHAR(40) NOT NULL UNIQUE, clave_hash VARCHAR(255) NOT NULL,
 rol VARCHAR(12) NOT NULL CHECK(rol IN ('admin','docente')),
 estado VARCHAR(12) NOT NULL DEFAULT 'activo', creado_en VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cursos (
 id INTEGER PRIMARY KEY AUTO_INCREMENT,
 nombre VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS documentos (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, titulo VARCHAR(200) NOT NULL,
 nombre_archivo VARCHAR(255), ruta_archivo VARCHAR(255),
 tamano INTEGER NOT NULL, paginas INTEGER NOT NULL, subido_por_id INTEGER NOT NULL,
 creado_en VARCHAR(30) NOT NULL, curso_id INTEGER NULL,
 compartido TINYINT NOT NULL DEFAULT 0,
 token_manual CHAR(48) DEFAULT NULL,
 UNIQUE(subido_por_id, token_manual),
 FOREIGN KEY(subido_por_id) REFERENCES usuarios(id),
 CONSTRAINT documentos_curso_fk FOREIGN KEY(curso_id) REFERENCES cursos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS preguntas (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, documento_id INTEGER NOT NULL,
 enunciado TEXT NOT NULL, opciones_json TEXT NOT NULL, correcta INTEGER,
 tipo VARCHAR(20) NOT NULL DEFAULT 'opcion_multiple', respuestas_aceptadas_json TEXT,
 explicacion TEXT NOT NULL, FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS salas_evaluacion (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, documento_id INTEGER,
 titulo VARCHAR(200) NOT NULL, docente VARCHAR(120) NOT NULL, codigo VARCHAR(12) NOT NULL UNIQUE,
 creado_por_id INTEGER NOT NULL, tiempo INTEGER NOT NULL, estado VARCHAR(15) NOT NULL DEFAULT 'espera',
 inicio DOUBLE, preguntas_json MEDIUMTEXT NOT NULL, creado_en VARCHAR(30) NOT NULL,
 es_entrada TINYINT NOT NULL DEFAULT 0, entrada_id INTEGER NULL UNIQUE,
 FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE SET NULL,
 FOREIGN KEY(creado_por_id) REFERENCES usuarios(id),
 CONSTRAINT salas_entrada_fk FOREIGN KEY(entrada_id) REFERENCES salas_evaluacion(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS participantes (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, sala_id INTEGER NOT NULL, nombre VARCHAR(100) NOT NULL,
 avatar INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, visto_en DOUBLE NOT NULL,
 creado_en VARCHAR(30) NOT NULL,
 codigo_seguimiento_hash CHAR(64), participante_entrada_id INTEGER,
 UNIQUE(sala_id, codigo_seguimiento_hash), UNIQUE(sala_id, participante_entrada_id),
 FOREIGN KEY(sala_id) REFERENCES salas_evaluacion(id) ON DELETE CASCADE,
 CONSTRAINT participantes_entrada_fk FOREIGN KEY(participante_entrada_id) REFERENCES participantes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS respuestas_participantes (
 id INTEGER PRIMARY KEY AUTO_INCREMENT, participante_id INTEGER NOT NULL, indice INTEGER NOT NULL,
 opcion INTEGER, texto VARCHAR(150), correcta INTEGER NOT NULL DEFAULT 0, tiempo DOUBLE NOT NULL,
 puntos INTEGER NOT NULL DEFAULT 0, UNIQUE(participante_id, indice),
 FOREIGN KEY(participante_id) REFERENCES participantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS configuraciones (nombre VARCHAR(80) PRIMARY KEY, valor TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS borradores (
 token VARCHAR(64) PRIMARY KEY, usuario_id INTEGER NOT NULL, titulo VARCHAR(200) NOT NULL,
 nombre_archivo VARCHAR(255) NOT NULL, ruta_archivo VARCHAR(255) NOT NULL,
 tamano INTEGER NOT NULL, paginas INTEGER NOT NULL, preguntas_json MEDIUMTEXT NOT NULL, creado_en DOUBLE NOT NULL,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS intentos_acceso (
 identificador VARCHAR(64) PRIMARY KEY, intentos INTEGER NOT NULL, ultimo DOUBLE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
