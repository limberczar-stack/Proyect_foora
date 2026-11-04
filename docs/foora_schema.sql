-- =====================================================================
-- FOORA - Esquema de base de datos (versión base / MVP)
-- Motor: MySQL 8 / MariaDB 10.6+  |  Pensado para Laravel (ids BIGINT UNSIGNED)
-- Multi-tenant: cada tabla de negocio lleva restaurante_id
-- Tablas estándar de Laravel que NO se repiten aquí:
--   password_reset_tokens, sessions, jobs, failed_jobs, cache, notifications
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. CATÁLOGOS Y PLANES
-- ---------------------------------------------------------------------
CREATE TABLE rubros (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(60) NOT NULL UNIQUE,          -- Pollería, Cevichería...
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE planes (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(40) NOT NULL UNIQUE,      -- Básico, Pro, Premium
  precio_mensual   DECIMAL(8,2) NOT NULL,
  max_mesas        SMALLINT UNSIGNED NULL,           -- NULL = ilimitado
  max_usuarios     SMALLINT UNSIGNED NULL,
  max_productos    SMALLINT UNSIGNED NULL,
  incluye_reservas TINYINT(1) NOT NULL DEFAULT 1,
  incluye_promociones TINYINT(1) NOT NULL DEFAULT 0,
  incluye_reportes TINYINT(1) NOT NULL DEFAULT 0,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE metodos_pago (
  id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo  VARCHAR(20) NOT NULL UNIQUE,               -- efectivo, yape, plin, tarjeta
  nombre  VARCHAR(40) NOT NULL,
  requiere_validacion TINYINT(1) NOT NULL DEFAULT 0, -- Yape/Plin: validar manualmente
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. RESTAURANTES (TENANTS) Y USUARIOS
-- ---------------------------------------------------------------------
CREATE TABLE restaurantes (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rubro_id            BIGINT UNSIGNED NULL,
  plan_id             BIGINT UNSIGNED NULL,
  nombre              VARCHAR(120) NOT NULL,
  slug                VARCHAR(140) NOT NULL UNIQUE,  -- foora.pe/el-buen-sabor
  descripcion         TEXT NULL,
  logo                VARCHAR(255) NULL,
  telefono            VARCHAR(20) NULL,
  email               VARCHAR(150) NULL,
  direccion           VARCHAR(255) NULL,
  ciudad              VARCHAR(80) NOT NULL DEFAULT 'Huánuco',
  latitud             DECIMAL(10,7) NULL,
  longitud            DECIMAL(10,7) NULL,
  -- Estado y ajustes generales (pantalla Configuración)
  estado_local        ENUM('abierto','cerrado') NOT NULL DEFAULT 'cerrado',
  visible_en_app      TINYINT(1) NOT NULL DEFAULT 1,
  acepta_pedidos_auto TINYINT(1) NOT NULL DEFAULT 0,
  sonido_nuevos_pedidos TINYINT(1) NOT NULL DEFAULT 1,
  promociones_habilitadas TINYINT(1) NOT NULL DEFAULT 0,
  acepta_delivery     TINYINT(1) NOT NULL DEFAULT 1,
  acepta_recojo       TINYINT(1) NOT NULL DEFAULT 1,
  acepta_reservas     TINYINT(1) NOT NULL DEFAULT 1,
  costo_delivery      DECIMAL(8,2) NOT NULL DEFAULT 0,
  pedido_minimo       DECIMAL(8,2) NOT NULL DEFAULT 0,
  tiempo_prep_min     SMALLINT UNSIGNED NULL,        -- tiempo estimado base (30-40 min)
  tolerancia_reserva_min SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  umbral_ocupacion_alta  TINYINT UNSIGNED NOT NULL DEFAULT 95, -- alerta "Ocupación alta"
  moneda              CHAR(3) NOT NULL DEFAULT 'PEN',
  zona_horaria        VARCHAR(40) NOT NULL DEFAULT 'America/Lima',
  config_notificaciones JSON NULL,                   -- {nuevos_pedidos:true, reservas:true, alertas:true}
  -- Calificación acumulada (se recalcula desde resenas)
  calificacion_promedio DECIMAL(2,1) NOT NULL DEFAULT 0,
  total_resenas       INT UNSIGNED NOT NULL DEFAULT 0,
  activo              TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  KEY idx_rest_ciudad (ciudad, visible_en_app, activo),
  CONSTRAINT fk_rest_rubro FOREIGN KEY (rubro_id) REFERENCES rubros(id) ON DELETE SET NULL,
  CONSTRAINT fk_rest_plan  FOREIGN KEY (plan_id)  REFERENCES planes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un solo login para todos: restaurante_id NULL = cliente final o superadmin FOORA
CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NULL,
  name            VARCHAR(120) NOT NULL,
  email           VARCHAR(150) NULL UNIQUE,
  telefono        VARCHAR(20) NULL,
  email_verified_at TIMESTAMP NULL,
  password        VARCHAR(255) NOT NULL,
  rol             ENUM('superadmin','admin','cajero','mesero','cocina','repartidor','cliente')
                  NOT NULL DEFAULT 'cliente',
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso_at TIMESTAMP NULL,
  remember_token  VARCHAR(100) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  KEY idx_users_rest_rol (restaurante_id, rol),
  KEY idx_users_tel (telefono),
  CONSTRAINT fk_users_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripciones (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  plan_id         BIGINT UNSIGNED NOT NULL,
  estado          ENUM('prueba','activa','vencida','cancelada') NOT NULL DEFAULT 'prueba',
  inicia_el       DATE NOT NULL,
  vence_el        DATE NOT NULL,
  precio_pactado  DECIMAL(8,2) NOT NULL DEFAULT 0,   -- 0 durante el tiempo gratuito
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_susc_rest (restaurante_id, estado, vence_el),
  CONSTRAINT fk_susc_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_susc_plan FOREIGN KEY (plan_id) REFERENCES planes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripcion_pagos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  suscripcion_id  BIGINT UNSIGNED NOT NULL,
  monto           DECIMAL(8,2) NOT NULL,
  metodo          VARCHAR(30) NULL,
  referencia      VARCHAR(80) NULL,
  periodo_desde   DATE NOT NULL,
  periodo_hasta   DATE NOT NULL,
  pagado_at       TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  CONSTRAINT fk_spag_susc FOREIGN KEY (suscripcion_id) REFERENCES suscripciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. CONFIGURACIÓN DEL LOCAL
-- ---------------------------------------------------------------------
CREATE TABLE horarios_atencion (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  dia_semana      TINYINT UNSIGNED NOT NULL,         -- 1=lunes ... 7=domingo
  abre            TIME NULL,
  cierra          TIME NULL,
  cerrado         TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_horario (restaurante_id, dia_semana),
  CONSTRAINT fk_hor_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE restaurante_metodos_pago (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  metodo_pago_id  BIGINT UNSIGNED NOT NULL,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  numero_cuenta   VARCHAR(30) NULL,                  -- celular de Yape/Plin del local
  titular         VARCHAR(120) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_rest_metodo (restaurante_id, metodo_pago_id),
  CONSTRAINT fk_rmp_rest   FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rmp_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE impresoras (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  nombre          VARCHAR(60) NOT NULL,              -- Impresora de cocina / barra
  tipo            ENUM('cocina','barra','caja') NOT NULL DEFAULT 'cocina',
  conexion        VARCHAR(80) NULL,                  -- IP o nombre en red
  activa          TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  CONSTRAINT fk_imp_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. CARTA (MENÚ)
-- ---------------------------------------------------------------------
CREATE TABLE categorias_producto (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  nombre          VARCHAR(80) NOT NULL,
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activa          TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_cat_rest (restaurante_id, orden),
  CONSTRAINT fk_cat_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  categoria_id    BIGINT UNSIGNED NULL,
  nombre          VARCHAR(120) NOT NULL,
  descripcion     TEXT NULL,                         -- si es NULL -> alerta "Menú incompleto"
  imagen          VARCHAR(255) NULL,                 -- idem
  precio          DECIMAL(8,2) NOT NULL,
  disponible      TINYINT(1) NOT NULL DEFAULT 1,
  destacado       TINYINT(1) NOT NULL DEFAULT 0,
  estacion        ENUM('cocina','barra') NOT NULL DEFAULT 'cocina', -- a qué impresora va
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  KEY idx_prod_rest (restaurante_id, categoria_id, disponible),
  CONSTRAINT fk_prod_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_cat  FOREIGN KEY (categoria_id) REFERENCES categorias_producto(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. MESAS Y ÁREAS
-- ---------------------------------------------------------------------
CREATE TABLE areas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  nombre          VARCHAR(60) NOT NULL,              -- Salón principal, Terraza, Patio, Segundo piso
  activa          TINYINT(1) NOT NULL DEFAULT 1,     -- "Activa o desactiva las áreas"
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_area (restaurante_id, nombre),
  CONSTRAINT fk_area_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mesas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  area_id         BIGINT UNSIGNED NOT NULL,
  codigo          VARCHAR(10) NOT NULL,              -- 1, 2, T1, T2...
  capacidad       TINYINT UNSIGNED NOT NULL,
  estado          ENUM('disponible','ocupada','reservada','fuera_servicio') NOT NULL DEFAULT 'disponible',
  pos_x           SMALLINT NULL,                     -- para el plano visual (opcional)
  pos_y           SMALLINT NULL,
  activa          TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_mesa (restaurante_id, codigo),
  KEY idx_mesa_estado (restaurante_id, estado),
  CONSTRAINT fk_mesa_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_mesa_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. REPARTIDORES Y PROMOCIONES (se necesitan antes de pedidos)
-- ---------------------------------------------------------------------
CREATE TABLE repartidores (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,              -- si tendrá login propio
  nombre          VARCHAR(120) NOT NULL,
  telefono        VARCHAR(20) NULL,
  vehiculo        VARCHAR(30) NULL,                  -- Moto, Bicicleta
  placa           VARCHAR(15) NULL,                  -- HCO-23F
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  CONSTRAINT fk_rep_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promociones (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  nombre          VARCHAR(120) NOT NULL,             -- 2x1 en Pollo a la Brasa
  descripcion     VARCHAR(255) NULL,
  imagen          VARCHAR(255) NULL,
  tipo            ENUM('2x1','porcentaje','monto_fijo','delivery_gratis','combo') NOT NULL,
  valor           DECIMAL(8,2) NULL,                 -- 10 (%), 5.00 (S/), precio combo...
  monto_minimo    DECIMAL(8,2) NULL,                 -- "pedidos mayores a S/ 60"
  aplica_delivery TINYINT(1) NOT NULL DEFAULT 1,
  aplica_recojo   TINYINT(1) NOT NULL DEFAULT 1,
  aplica_salon    TINYINT(1) NOT NULL DEFAULT 1,
  fecha_inicio    DATE NOT NULL,
  fecha_fin       DATE NOT NULL,
  limite_usos     INT UNSIGNED NULL,                 -- NULL = sin límite
  usos            INT UNSIGNED NOT NULL DEFAULT 0,
  activa          TINYINT(1) NOT NULL DEFAULT 1,     -- Estado (activa/programada/finalizada) se deriva con fechas
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  KEY idx_promo_vig (restaurante_id, activa, fecha_inicio, fecha_fin),
  CONSTRAINT fk_promo_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si no hay filas aquí para una promoción, aplica a todos los productos
CREATE TABLE promocion_productos (
  promocion_id    BIGINT UNSIGNED NOT NULL,
  producto_id     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (promocion_id, producto_id),
  CONSTRAINT fk_pp_promo FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_prod  FOREIGN KEY (producto_id)  REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. PEDIDOS
-- ---------------------------------------------------------------------
CREATE TABLE pedidos (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id      BIGINT UNSIGNED NOT NULL,
  numero              INT UNSIGNED NOT NULL,         -- #1258, correlativo por restaurante
  user_id             BIGINT UNSIGNED NULL,          -- cliente registrado (NULL si es invitado)
  nombre_cliente      VARCHAR(120) NOT NULL,         -- copia: sirve también para pedidos sin cuenta
  telefono_cliente    VARCHAR(20) NOT NULL,
  tipo                ENUM('delivery','recojo','salon') NOT NULL,
  origen              ENUM('app','local','telefono') NOT NULL DEFAULT 'app',
  mesa_id             BIGINT UNSIGNED NULL,          -- pedidos de salón
  -- Entrega
  direccion_entrega   VARCHAR(255) NULL,
  referencia_entrega  VARCHAR(255) NULL,             -- "Casa azul con puerta negra"
  latitud             DECIMAL(10,7) NULL,
  longitud            DECIMAL(10,7) NULL,
  repartidor_id       BIGINT UNSIGNED NULL,
  -- Pago
  metodo_pago_id      BIGINT UNSIGNED NOT NULL,
  estado_pago         ENUM('pendiente','por_verificar','pagado','rechazado') NOT NULL DEFAULT 'pendiente',
  referencia_pago     VARCHAR(50) NULL,              -- código de operación Yape/Plin
  pago_validado_por   BIGINT UNSIGNED NULL,
  pago_validado_at    TIMESTAMP NULL,
  -- Montos
  subtotal            DECIMAL(10,2) NOT NULL DEFAULT 0,
  descuento           DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_delivery      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total               DECIMAL(10,2) NOT NULL DEFAULT 0,
  promocion_id        BIGINT UNSIGNED NULL,
  observaciones       VARCHAR(500) NULL,             -- "Sin ají por favor"
  -- Flujo
  estado              ENUM('nuevo','en_preparacion','listo','en_camino','entregado','cancelado')
                      NOT NULL DEFAULT 'nuevo',
  tiempo_estimado_min SMALLINT UNSIGNED NULL,
  aceptado_at         TIMESTAMP NULL,
  listo_at            TIMESTAMP NULL,
  salida_at           TIMESTAMP NULL,
  eta_at              TIMESTAMP NULL,
  recoger_antes_de    TIMESTAMP NULL,
  entregado_at        TIMESTAMP NULL,
  -- Cancelación
  cancelado_at        TIMESTAMP NULL,
  cancelacion_motivo  ENUM('cliente_no_respondio','cliente_cancelo','pago_no_confirmado',
                           'cliente_no_llego','tiempo_espera_excedido','problema_delivery',
                           'restaurante_rechazo','otro') NULL,
  cancelacion_detalle VARCHAR(255) NULL,
  registrado_como_perdida TINYINT(1) NOT NULL DEFAULT 0,
  creado_por          BIGINT UNSIGNED NULL,          -- usuario del local que lo digitó
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_pedido_num (restaurante_id, numero),
  KEY idx_ped_estado (restaurante_id, estado, created_at),
  KEY idx_ped_fecha  (restaurante_id, created_at),
  KEY idx_ped_cliente (user_id),
  KEY idx_ped_tel (restaurante_id, telefono_cliente),
  CONSTRAINT fk_ped_rest  FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ped_user  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_mesa  FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_rep   FOREIGN KEY (repartidor_id) REFERENCES repartidores(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_pago  FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id),
  CONSTRAINT fk_ped_val   FOREIGN KEY (pago_validado_por) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_promo FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_crea  FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id       BIGINT UNSIGNED NOT NULL,
  producto_id     BIGINT UNSIGNED NULL,
  nombre          VARCHAR(120) NOT NULL,             -- copia del nombre al momento del pedido
  cantidad        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(8,2) NOT NULL,
  subtotal        DECIMAL(10,2) NOT NULL,
  estado          ENUM('pendiente','en_preparacion','listo') NOT NULL DEFAULT 'pendiente', -- progreso 2 de 3
  observacion     VARCHAR(255) NULL,
  tiempo_estimado_min SMALLINT UNSIGNED NULL,
  listo_at        TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_item_pedido (pedido_id),
  CONSTRAINT fk_item_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_prod   FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Línea de tiempo del pedido: Aceptado > Preparado > Listo > En camino > Entregado
CREATE TABLE pedido_historial (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id       BIGINT UNSIGNED NOT NULL,
  estado_anterior VARCHAR(20) NULL,                  -- "Estado anterior: en preparación"
  estado_nuevo    VARCHAR(20) NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  nota            VARCHAR(255) NULL,
  created_at      TIMESTAMP NULL,
  KEY idx_ph_pedido (pedido_id, created_at),
  CONSTRAINT fk_ph_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ph_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promocion_usos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promocion_id    BIGINT UNSIGNED NOT NULL,
  pedido_id       BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  descuento_aplicado DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NULL,
  UNIQUE KEY uq_promo_pedido (promocion_id, pedido_id),
  CONSTRAINT fk_pu_promo  FOREIGN KEY (promocion_id) REFERENCES promociones(id) ON DELETE CASCADE,
  CONSTRAINT fk_pu_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pu_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. RESERVAS Y OCUPACIÓN DE MESAS
-- ---------------------------------------------------------------------
CREATE TABLE reservas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(25) NOT NULL UNIQUE,       -- RES-240525-0012
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  nombre_cliente  VARCHAR(120) NOT NULL,
  telefono_cliente VARCHAR(20) NOT NULL,
  mesa_id         BIGINT UNSIGNED NULL,
  fecha           DATE NOT NULL,
  hora            TIME NOT NULL,
  personas        TINYINT UNSIGNED NOT NULL,
  observaciones   VARCHAR(255) NULL,                 -- Cumpleaños, Aniversario, Ventana
  estado          ENUM('pendiente','confirmada','cancelada','no_asistio','completada')
                  NOT NULL DEFAULT 'pendiente',
  origen          ENUM('app','local','telefono') NOT NULL DEFAULT 'app',
  confirmada_at   TIMESTAMP NULL,
  confirmada_por  VARCHAR(30) NULL,                  -- 'cliente' o nombre del usuario
  cancelada_at    TIMESTAMP NULL,
  cancelacion_motivo VARCHAR(255) NULL,
  recordatorio_enviado_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_res_dia   (restaurante_id, fecha, hora),
  KEY idx_res_estado(restaurante_id, estado, fecha),
  KEY idx_res_mesa  (mesa_id, fecha, hora),
  CONSTRAINT fk_res_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_res_mesa FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserva_aplazamientos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id      BIGINT UNSIGNED NOT NULL,
  hora_original   TIME NOT NULL,
  hora_solicitada TIME NOT NULL,                     -- 7:30 PM -> 7:45 PM
  motivo          VARCHAR(255) NULL,                 -- "Hay tráfico, llego en 10 min"
  estado          ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  resuelta_por    BIGINT UNSIGNED NULL,
  resuelta_at     TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_apl_estado (estado, created_at),
  CONSTRAINT fk_apl_res  FOREIGN KEY (reserva_id) REFERENCES reservas(id) ON DELETE CASCADE,
  CONSTRAINT fk_apl_user FOREIGN KEY (resuelta_por) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de mesas: quién la atendió, cuánto tiempo y cuánto consumió
CREATE TABLE ocupaciones_mesa (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  mesa_id         BIGINT UNSIGNED NOT NULL,
  reserva_id      BIGINT UNSIGNED NULL,
  pedido_id       BIGINT UNSIGNED NULL,
  mesero_id       BIGINT UNSIGNED NULL,
  personas        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ingreso_at      TIMESTAMP NOT NULL,
  salida_at       TIMESTAMP NULL,                    -- NULL = mesa aún ocupada
  consumo_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_ocu_mesa (mesa_id, salida_at),
  KEY idx_ocu_rest (restaurante_id, ingreso_at),
  CONSTRAINT fk_ocu_rest   FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocu_mesa   FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocu_res    FOREIGN KEY (reserva_id) REFERENCES reservas(id) ON DELETE SET NULL,
  CONSTRAINT fk_ocu_ped    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ocu_mesero FOREIGN KEY (mesero_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. ALERTAS Y ACTIVIDAD
-- ---------------------------------------------------------------------
CREATE TABLE alertas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  tipo            ENUM('pago_pendiente','pedido_sin_respuesta','reserva_por_vencer',
                       'solicitud_aplazamiento','ocupacion_alta','menu_incompleto','otro') NOT NULL,
  prioridad       ENUM('urgente','media','informativa') NOT NULL,
  titulo          VARCHAR(120) NOT NULL,
  mensaje         VARCHAR(255) NULL,
  referencia_tipo VARCHAR(30) NULL,                  -- 'pedido', 'reserva', 'producto'
  referencia_id   BIGINT UNSIGNED NULL,
  leida           TINYINT(1) NOT NULL DEFAULT 0,
  leida_at        TIMESTAMP NULL,
  resuelta_at     TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_alerta_pend (restaurante_id, resuelta_at, prioridad, created_at),
  CONSTRAINT fk_alerta_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE actividad_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  modulo          ENUM('pedidos','reservas','mesas','promociones','menu','configuracion','usuarios') NOT NULL,
  accion          VARCHAR(40) NOT NULL,              -- creo_pedido, actualizo_estado, cancelo_reserva...
  descripcion     VARCHAR(255) NULL,
  subject_type    VARCHAR(30) NULL,
  subject_id      BIGINT UNSIGNED NULL,
  datos_antes     JSON NULL,                         -- permite "Revertir acción"
  datos_despues   JSON NULL,
  ip              VARCHAR(45) NULL,
  dispositivo     VARCHAR(20) NULL,                  -- Web / Móvil / Tablet
  navegador       VARCHAR(80) NULL,
  revertible      TINYINT(1) NOT NULL DEFAULT 0,
  revertida_at    TIMESTAMP NULL,
  created_at      TIMESTAMP NULL,
  KEY idx_act_fecha (restaurante_id, created_at),
  KEY idx_act_user  (restaurante_id, user_id, created_at),
  CONSTRAINT fk_act_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_act_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. APP DEL CLIENTE
-- ---------------------------------------------------------------------
CREATE TABLE favoritos (
  user_id         BIGINT UNSIGNED NOT NULL,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NULL,
  PRIMARY KEY (user_id, restaurante_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE direcciones_cliente (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  alias           VARCHAR(40) NULL,                  -- Casa, Trabajo
  direccion       VARCHAR(255) NOT NULL,
  referencia      VARCHAR(255) NULL,
  latitud         DECIMAL(10,7) NULL,
  longitud        DECIMAL(10,7) NULL,
  predeterminada  TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  CONSTRAINT fk_dir_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resenas (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id  BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  pedido_id       BIGINT UNSIGNED NULL,
  puntuacion      TINYINT UNSIGNED NOT NULL,         -- 1 a 5
  comentario      VARCHAR(500) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_resena_pedido (pedido_id),
  KEY idx_resena_rest (restaurante_id, created_at),
  CONSTRAINT fk_resena_rest   FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_resena_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_resena_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- DATOS SEMILLA (para empezar a probar)
-- ---------------------------------------------------------------------
INSERT INTO rubros (nombre, created_at, updated_at) VALUES
 ('Pollería', NOW(), NOW()), ('Cevichería', NOW(), NOW()), ('Cafetería', NOW(), NOW()),
 ('Fast food', NOW(), NOW()), ('Chifa', NOW(), NOW());

INSERT INTO planes (nombre, precio_mensual, incluye_reservas, incluye_promociones, incluye_reportes, created_at, updated_at) VALUES
 ('Básico',  39.00, 1, 0, 0, NOW(), NOW()),
 ('Pro',     79.00, 1, 1, 0, NOW(), NOW()),
 ('Premium', 129.00, 1, 1, 1, NOW(), NOW());
-- Ajusta qué incluye cada plan: el PPT define los precios, no las diferencias entre planes.

INSERT INTO metodos_pago (codigo, nombre, requiere_validacion, created_at, updated_at) VALUES
 ('efectivo', 'Efectivo', 0, NOW(), NOW()),
 ('yape',     'Yape',     1, NOW(), NOW()),
 ('plin',     'Plin',     1, NOW(), NOW()),
 ('tarjeta',  'Tarjeta (POS)', 0, NOW(), NOW());
