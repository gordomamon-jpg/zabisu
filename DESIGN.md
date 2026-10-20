# Zabisu: guía de diseño "Pizarrón de fonda"

Contrato visual de las pantallas del cliente. La primera que lo usa es
`cliente/menu_pizarron.php`, con sus estilos en `assets/css/menu-pizarron.css`. Toda pantalla
nueva o rediseñada (pedido, pago, confirmación, estado) toma los colores, las letras y las piezas
de aquí, sin inventar piezas propias.

## La idea

Comida corrida hecha a mano para oficinas. La pantalla es el pizarrón de una fonda: fondo oscuro y
listas ordenadas tipo Configuración del iPhone, con pocas piezas de papel estraza pegadas a mano.
**Las imperfecciones van a propósito y solo en la decoración.** El texto, los precios y los botones
van derechos y limpios, para que se lea fácil en el celular.

## Colores

| Token | Hex | Uso | Contraste |
|---|---|---|---|
| `--pz-pizarra` | `#151413` | Fondo | — |
| `--pz-grupo` | `#201E1C` | Fondo de las listas agrupadas | — |
| `--pz-linea` | `#2F2C29` | Separadores | — |
| `--pz-gis` | `#ECEBE7` | Texto principal | 15.4:1 sobre pizarra |
| `--pz-gris` | `#A5A29B` | Texto secundario y descripciones | 7.2:1 sobre pizarra |
| `--pz-naranja` | `#FF7A00` | Marca: pincel, botón principal, avisos de tiempo | 7.0:1 sobre pizarra |
| `--pz-estraza` | `#C49A6C` | Cintas, tiras y pestaña activa | 7.2:1 sobre pizarra |
| `--pz-estraza-cl` | `#D9B88F` | Boleto y eslogan | — |
| `--pz-tinta` | `#22180F` | Texto sobre estraza | 6.8:1 sobre estraza |

Reglas:
- **Sobre naranja, la letra va negra (`#111`), nunca blanca.** La blanca da 2.6:1 y no se lee bien.
- El naranja se reserva para la marca, la acción principal y el tiempo. No se usa en textos de relleno.
- Nada de degradados, brillos, vidrio esmerilado (`backdrop-filter`) ni sombras de color.

## Letras

| Rol | Fuente | Dónde |
|---|---|---|
| Texto, precios y botones | Letra del sistema (`system-ui`) | Todo lo que se lee |
| Pincel | Kaushan Script | Título del día, eslogan y notas a mano. Una o dos veces por pantalla |
| Rótulo | Alfa Slab One | Nombre "Zabisu", cintas, hora de cierre, agotado y boleto |

Las dos fuentes de marca se alojan en `assets/fonts/`, sin depender de Google. Sin mayúsculas
sostenidas, sin letras espaciadas y sin "·" para unir datos.

## Piezas

- **Encabezado sin sello**: solo logo, "Zabisu" y el lema. Cada dato va una vez: el día en el título ("Menú del martes") y la hora de cierre abajo, con la cuenta regresiva. La tinta dispareja (`filter:url(#pz-tinta)`) queda para el sello de "Agotado".
- **Pincel**: trazo naranja con la letra negra encima, para el título de la pantalla.
- **Cinta de estraza**: rótulo de cada sección, con las orillas rotas (`clip-path`) y girada entre −1.8° y 1.1°.
- **Lista agrupada**: filas tipo Configuración. Cada platillo lleva su **tira de estraza** a la izquierda (sin ícono), su nombre y su descripción.
- **Boleto**: papel estraza claro con muescas, hoyito y línea de corte, para lo que "va incluido".
- **Nota a mano**: texto en pincel naranja con flechita, solo para información real (por ejemplo, "el más pedido").
- **Óvalo a mano**: contorno naranja irregular para la cuenta regresiva. Con menos de 10 min se rellena.
- **Agotado**: el platillo tachado y atenuado, con un sello gris de "Agotado".
- **Botón principal**: fijo abajo, naranja con letra negra, con la acción y el precio ("Ordenar ahora · $110" en dos extremos). Sin flechas pegadas al texto.

## Imperfecciones: qué sí y qué no

Sí: giros pequeños (de −3° a 3°, varios distintos en la misma pantalla), orillas rotas y tinta
dispareja en sellos, cintas, tiras, boletos y notas.

No: texto girado o deformado para leer, precios chuecos, botones chuecos, ni más de unas cinco
piezas decorativas visibles a la vez.

## Accesibilidad

- Contraste AA o mejor en todo el texto (ver la tabla de colores).
- Áreas táctiles de 44 px o más.
- Foco visible con contorno naranja (`:focus-visible`).
- Las tiras, estrellas y flechas son decorativas (`aria-hidden`).
- Sin animaciones de entrada. Las transiciones respetan `prefers-reduced-motion`.

## Vista previa

`?diseno=nuevo` en cualquier pantalla del cliente activa el diseño en ese navegador por 30 días
(cookie `zb_diseno`, en `includes/diseno.php`), y `?diseno=actual` lo quita. Los clientes que no
usan el enlace ven el diseño de siempre.

| Pantalla | Cómo se pinta |
|---|---|
| Menú | Vista propia: `cliente/menu_pizarron.php` + `menu-pizarron.css` |
| Pedido y "Mi pedido" | Mismo HTML y JavaScript de siempre + `pedido-pizarron.css` (clase `pz-pedido` en el body) |
| Pago y confirmación | Mismo HTML y JavaScript de siempre + `cliente-pizarron.css` (clase `pz-cliente`) |

Los filtros de orillas irregulares (`#pz-borde`, `#pz-tinta`) viven en `includes/pz_filtros.php`.
