-- =====================================================================
-- FOORA - Cambios de la CARTA (sobre foora_schema.sql)
-- Basado en cartas reales: pollería/parrillería (Mediterráneo) y chifa (Wong).
-- Aplicar como nuevas migraciones de Laravel (no editar las anteriores).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. CATEGORÍAS CON SUBCATEGORÍAS
--    Ej.: Carta Bar > Vinos Tintos > Perú  |  Bebidas > Frías
-- ---------------------------------------------------------------------
ALTER TABLE categorias_producto
  ADD COLUMN parent_id   BIGINT UNSIGNED NULL AFTER restaurante_id,
  ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre,
  ADD COLUMN con_fotos   TINYINT(1) NOT NULL DEFAULT 1 AFTER activa,  -- 0 en bebidas, vinos, etc.
  ADD KEY idx_cat_parent (parent_id),
  ADD CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id)
      REFERENCES categorias_producto(id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 2. PRODUCTOS: tipo, personas (combos) y etiquetas
-- ---------------------------------------------------------------------
ALTER TABLE productos
  ADD COLUMN tipo ENUM('simple','variantes','combo') NOT NULL DEFAULT 'simple' AFTER categoria_id,
  ADD COLUMN personas TINYINT UNSIGNED NULL AFTER tipo,   -- combos / banquetes: "para 2", "para 4"
  ADD COLUMN etiquetas JSON NULL AFTER estacion;          -- ["vegetariano","nuevo","picante","sin_gluten"]
-- Nota: `precio` se mantiene. En productos con variantes guarda el precio
-- "desde" (el menor de sus variantes) y lo recalcula la aplicación.

-- ---------------------------------------------------------------------
-- 3. VARIANTES CON PRECIO PROPIO
--    Ej.: Chaufa de pollo: Taza 11 / Personal 22 / Mediano 32 / Familiar 44
--         Limonada: Vaso 7.50 / 1/2 litro 12.50 / Jarra 1L 23.50
--         1/4 de pollo: Pierna 30.90 / Pecho 32.90
--    No todas las variantes existen en todos los productos.
-- ---------------------------------------------------------------------
CREATE TABLE producto_variantes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producto_id  BIGINT UNSIGNED NOT NULL,
  nombre       VARCHAR(60) NOT NULL,
  precio       DECIMAL(8,2) NOT NULL,
  disponible   TINYINT(1) NOT NULL DEFAULT 1,
  orden        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_var_prod (producto_id, orden),
  CONSTRAINT fk_var_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. GRUPOS DE OPCIONES Y ADICIONALES (reutilizables entre productos)
--    Ej.: "Elige tu guarnición" (única, extra S/ 0)
--         "Sabor": salado (chijaukay) / dulce (tipakay) (única, extra S/ 0)
--         "Adicionales": piña +3, plátano +3, huevo +3, tocino +5 (múltiple)
--         "Gaseosa": Coca Cola, Inca Kola, Fanta, Sprite (única, S/ 0)
--         Combo Wong: "Chaufa de" pollo / chancho (única, S/ 0)
-- ---------------------------------------------------------------------
CREATE TABLE grupos_opcion (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurante_id BIGINT UNSIGNED NOT NULL,
  nombre         VARCHAR(80) NOT NULL,                 -- lo que ve el cliente: "Elige tu guarnición"
  tipo           ENUM('unica','multiple') NOT NULL DEFAULT 'unica',
  obligatorio    TINYINT(1) NOT NULL DEFAULT 0,
  min_opciones   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_opciones   TINYINT UNSIGNED NULL,                -- NULL = sin límite (solo tipo múltiple)
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_gop_rest (restaurante_id),
  CONSTRAINT fk_gop_rest FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE opciones (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id     BIGINT UNSIGNED NOT NULL,
  nombre       VARCHAR(80) NOT NULL,
  precio_extra DECIMAL(8,2) NOT NULL DEFAULT 0,        -- 0 = sin costo
  disponible   TINYINT(1) NOT NULL DEFAULT 1,
  orden        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_op_grupo (grupo_id, orden),
  CONSTRAINT fk_op_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_opcion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE producto_grupos_opcion (
  producto_id BIGINT UNSIGNED NOT NULL,
  grupo_id    BIGINT UNSIGNED NOT NULL,
  orden       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (producto_id, grupo_id),
  CONSTRAINT fk_pgo_prod  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgo_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_opcion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PEDIDOS: guardar la variante y las opciones elegidas
--    precio_unitario = precio de la variante (o del producto) + extras elegidos,
--    así subtotal = cantidad x precio_unitario sigue siendo válido.
-- ---------------------------------------------------------------------
ALTER TABLE pedido_items
  ADD COLUMN variante_id     BIGINT UNSIGNED NULL AFTER producto_id,
  ADD COLUMN variante_nombre VARCHAR(60) NULL AFTER variante_id,
  ADD CONSTRAINT fk_item_var FOREIGN KEY (variante_id)
      REFERENCES producto_variantes(id) ON DELETE SET NULL;

CREATE TABLE pedido_item_opciones (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_item_id BIGINT UNSIGNED NOT NULL,
  opcion_id      BIGINT UNSIGNED NULL,
  grupo_nombre   VARCHAR(80) NOT NULL,                 -- copia: "Adicionales"
  nombre         VARCHAR(80) NOT NULL,                 -- copia: "Piña a la parrilla"
  precio_extra   DECIMAL(8,2) NOT NULL DEFAULT 0,
  KEY idx_pio_item (pedido_item_id),
  CONSTRAINT fk_pio_item   FOREIGN KEY (pedido_item_id) REFERENCES pedido_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_pio_opcion FOREIGN KEY (opcion_id) REFERENCES opciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
