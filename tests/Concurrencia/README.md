# Pruebas de concurrencia (MySQL, procesos reales)

Están fuera de phpunit porque la suite usa SQLite en memoria, donde no hay bloqueos de fila reales. Cada script lanza procesos `php` que arrancan a la vez y siempre incluye un control negativo: el mismo flujo sin la protección, que **debe** fallar. Si el control no reproduce el problema, el script lo cuenta como fallo, porque la prueba no demostraría nada.

## Cómo correrlas

1. Crea la base (una sola vez), con el usuario y la contraseña de MySQL de tu `.env`:

   ```sql
   CREATE DATABASE foora_concurrencia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Sin caché de configuración (`php artisan config:clear`), ejecuta:

   ```bash
   php tests/Concurrencia/limite_mesas.php
   php tests/Concurrencia/cuentas.php
   php tests/Concurrencia/cuentas.php --procesos=12
   php tests/Concurrencia/pedidos_salon.php
   php tests/Concurrencia/pedidos_salon.php --procesos=12
   ```

Devuelven 0 si todo sale como se espera y 1 si alguna corrida falla. `cuentas.php` y `pedidos_salon.php` tardan unos minutos: reconstruyen la base antes de cada corrida.

## Seguridad

Los scripts **borran la base entera** (`migrate:fresh`). Por eso abortan, con código 2, si la conexión no es MySQL o si la base no se llama exactamente `foora_concurrencia`, por ejemplo cuando hay un `DB_URL` que apunta a otra. La comprobación está en `_arranque.php` y se repite justo antes de cada reconstrucción.

## limite_mesas.php

Comprueba que `planes.max_mesas` no se pasa cuando varios admins crean, agregan o reactivan mesas a la vez. Cada escenario deja el local a una mesa de su límite (5):

| Escenario | Qué hace cada proceso | Resultado esperado |
|---|---|---|
| `crear` | `GestionarMesas::crearMesa` | 5 activas, 1 creada, el resto rechazadas por el límite |
| `varias` | `GestionarMesas::agregarVarias` (1 mesa) | Igual |
| `reactivar` | `GestionarMesas::cambiarActivaMesa` | Igual |
| `control` | La misma regla, sin `lockForUpdate` | Se pasa del límite |

## cuentas.php

Comprueba las cuentas de salón (`GestionarCuentas::abrir`) en tres escenarios:

| Escenario | Qué hace cada proceso | Con bloqueo |
|---|---|---|
| `misma_mesa` | N procesos abren una cuenta en la misma mesa | 1 cuenta; los demás, 409 por la regla |
| `cruzadas` | Por cada par de mesas, uno abre A+B y otro B+A | Gana uno por par, sin interbloqueo |
| `numeracion` | N procesos abren cuentas en mesas distintas | Números 1..N, sin huecos ni duplicados |

Cada escenario corre en tres modos. Los dos controles ejecutan el código real del servicio, con una subclase que solo quita el bloqueo del restaurante:

| Modo | Qué se quita | Qué debe pasar |
|---|---|---|
| `bloqueo` | Nada | Sale limpio: sin errores, sin rechazos del índice, invariante intacto |
| `sin_bloqueo` | El `lockForUpdate` del restaurante | Los índices únicos siguen evitando el dato malo, pero los perdedores chocan con el índice en vez de recibir la regla, y en `numeracion` se pierden aperturas legítimas |
| `sin_indices` | El bloqueo y los índices `uq_ocu_mesa_abierta` y `uq_cuenta_num` | Ver los dos ritmos |

Sin bloqueo ni índices, la carrera falla de dos formas distintas, así que se corre con dos ritmos:

- **`a_la_vez`**: todos escriben al mismo tiempo. No llegan a ocupar dos veces la mesa de forma fiable: cada transacción tiene un bloqueo compartido de la fila de la mesa (por la clave foránea de la ocupación) y todas quieren actualizarla, así que InnoDB las interbloquea y mata a casi todas con el error 1213. Es el interbloqueo que el bloqueo del restaurante evita.
- **`escalonado`**: cada proceso tarda más que el anterior entre leer y escribir, y escribe con una lectura vieja cuando el anterior ya confirmó. Aquí el dato sí se corrompe: varias cuentas abiertas en la misma mesa y números repetidos.

Para que la carrera sea reproducible, cada proceso espera justo después de comprobar que las mesas están libres y después de leer el último número: 150 ms todos (`a_la_vez`), o 150 ms más 300 ms por cada proceso anterior (`escalonado`). La espera depende del ritmo, nunca del modo.

## pedidos_salon.php

Comprueba las rondas de salón (`CrearPedidoSalon`) y su cruce con las operaciones de la cuenta (`GestionarCuentas`) y con los pedidos de la vitrina (`CrearPedido`). La base se reconstruye antes de cada corrida, así el control sin índices no contamina a los demás.

| Escenario | Qué pasa a la vez | Con bloqueo | Control negativo |
|---|---|---|---|
| `misma_clave` | N procesos envían la misma ronda con la misma clave | 1 pedido, y los N reciben ese mismo | Sin bloqueo: el índice evita el duplicado, pero los reenvíos reciben 409 en vez de su pedido. Sin índices: varias rondas con la misma clave |
| `ronda_y_cierre` | Por cada cuenta, un proceso envía una ronda y otro la cierra | Nunca queda un pedido vivo (sin servir o sin pagar) en una cuenta cerrada | Sin bloqueo: aparece |
| `numeracion` | N pedidos de la vitrina y N rondas de salón | Números 1..2N sin huecos ni duplicados | Rondas sin bloqueo y sin `uq_pedido_num`: números repetidos |
| `fusion_y_mesa` | Por cada par, uno fusiona las cuentas y otro agrega una mesa a la que se va a fusionar | Gana uno, el otro recibe 409 | Sin bloqueo: una mesa queda ocupada por una cuenta ya fusionada |

En el control sin índices se quitan `uq_ped_idempotencia`, `uq_pedido_num` y `uq_ped_ronda`. Este último también impide dos rondas iguales en la misma cuenta. MySQL lo usa como índice de la FK de `cuenta_id`, así que el script agrega antes un índice simple en su lugar.

En `numeracion` los pedidos de la vitrina siempre usan `CrearPedido` tal cual, con su bloqueo. Basta con que las rondas no bloqueen para que los números choquen.
