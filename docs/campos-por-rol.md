# Campos por rol en las plantillas (`rol='gestion'`)

Guía rápida para quien edite las plantillas ODT (los ficheros de
`fixtures/*.odt` y los que se suban desde **Documentos → Tipos de documento**
en wp-admin). Explica el
atributo `rol` de los marcadores TinyButStrong/OpenTBS y cómo afecta al
formulario que ve cada persona.

## Qué hace

Cada marcador de la plantilla puede llevar un atributo `rol` con dos valores
posibles:

- `rol='area'` (o sin indicarlo — es el valor por defecto): el campo lo
  rellena el área que crea el documento.
- `rol='gestion'`: el campo lo rellena gestión documental (o administración),
  no el área.

```
[gasto_numero;type='number';title='Gasto total';rol='gestion']
```

El alias `role` funciona igual (`role='gestion'`); usa el que prefieras, pero
sé consistente dentro de la misma plantilla.

## Efecto en la aplicación

- El área **no ve** los campos `rol='gestion'` al crear o editar su
  documento — ni en el formulario ni en la ficha de detalle.
- Gestión documental y administración ven **todos** los campos, con los de
  gestión agrupados aparte bajo el epígrafe «Datos oficiales · los completa
  gestión documental».
- Esto no es solo una cuestión de qué se muestra: aunque alguien manipulase
  el formulario a mano, un valor posteado para un campo `gestion` por una
  cuenta de área se ignora al guardar (se conserva el valor que ya hubiera).
  La comprobación se hace en el mismo sitio donde se guarda el dato, no solo
  al pintar el formulario.

## En un bloque repetible

Poner `rol='gestion'` en la etiqueta de apertura de un bloque lo propaga a
**todos** los campos del bloque y de sus subrepetidores, sin tener que
repetirlo campo a campo:

```
[servicios;block=begin;sub1=conceptos;rol='gestion']
  [servicios.proveedor]
  [servicios_sub1.concepto]
  [servicios_sub1.cantidad]
[servicios;block=end]
```

Aquí, `proveedor`, `concepto` y `cantidad` son todos `gestion` aunque
ninguno lo diga explícitamente.

## Cuándo un tipo de documento «pasa por gestión»

Un tipo de documento pasa por el paso intermedio **En gestión** del flujo
(ver `docs/flujo-documentos.md`) si se cumple cualquiera de estas dos
condiciones:

1. Tiene marcada la casilla **«Pasa por gestión documental»** en
   **Documentos → Tipos de documento**, o
2. Su plantilla activa contiene **algún** campo `rol='gestion'` — no hace
   falta marcar la casilla a mano, basta con usar el atributo.

Si no se cumple ninguna, el tipo salta directo de **Borrador** a
**En revisión**, y `rol='gestion'` no tiene ningún efecto (no hay nadie de
gestión documental en el circuito de ese tipo).

## Ejemplos ya usados en el repositorio

- `fixtures/propuestagasto.odt`: los importes y proveedores
  (`gasto_letra`, `gasto_numero`, `partida`, los bloques `servicios`,
  `suministros`, `expertos`…) son `rol='gestion'`; los datos que redacta el
  área (título, curso, objeto, destinatarios…) se quedan sin marcar.
- `fixtures/resolucion.odt`: `numero_resolucion`, `fecha_resolucion`,
  `expediente`, `organo_firmante` y el cuerpo de la resolución
  (`antecedentes`, `fundamentos`, `resuelvo`) son `rol='gestion'` — son los
  datos que asigna gestión documental al formalizar la resolución.

## Al tocar una plantilla

- Añadir o quitar `rol='gestion'` en una plantilla ya usada cambia
  inmediatamente qué ve cada rol en los documentos existentes de ese tipo:
  no hace falta ninguna migración.
- Después de editar una plantilla, ejecuta la suite de generación
  (`make test-generation`) para comprobar que el merge OpenTBS sigue
  funcionando con los marcadores tal y como han quedado.
