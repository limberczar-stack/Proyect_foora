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
   ```

Devuelven 0 si todo sale como se espera y 1 si alguna corrida falla. `cuentas.php` tarda unos minutos: reconstruye la base antes de cada una de sus 13 corridas.

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
