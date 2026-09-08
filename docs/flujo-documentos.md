# Flujo de documentos y roles

Guía funcional del ciclo de vida de un documento en Documentate: quién hace
qué, en qué orden y qué pasa cuando algo se devuelve. Pensada para el equipo
(no es una referencia de código; para eso está `ARCHITECTURE.md`).

## Los roles

| Rol | Quién | Qué hace |
|---|---|---|
| **Área** | Cualquier persona con permiso de edición que no sea revisión ni jefatura (cuenta de demostración: `author1`, ámbito «Departamento de Proyectos») | Crea el documento, rellena sus propios datos, lo envía y ve los documentos de su área |
| **Revisión** | Cuentas con el rol «Revisión» (cuenta de demostración: `editor1`, ámbito «Organización») | La persona junto a la jefatura que revisa los documentos de su ámbito y completa sus datos oficiales; los pasa a aprobación o los devuelve al área |
| **Jefatura de servicio** | Cuentas con el rol «Jefatura de servicio» (cuenta de demostración: `jefatura1`, ámbito «Organización») | Aprueba y publica, o devuelve con un motivo a revisión o al área. No es administradora del sitio |
| **Administración** | Administradores del sitio (cuenta de demostración: `admin`) | Puede hacer todo lo anterior en cualquier documento; además archiva, desarchiva y devuelve un aprobado a aprobación (solo desde wp-admin) |

Lo que cada persona ve depende de su **ámbito** (la categoría asignada en su
perfil), no de su rol: todas las personas de un área ven los documentos de su
área; revisión y jefatura ven todos los de su ámbito porque su categoría está
más arriba en el árbol (el servicio, del que cuelgan las áreas). Nadie ve nada
fuera de su rama, salvo administración.

Una misma persona puede ser revisión y, a la vez, área para su propio ámbito
(así es `editor1` en la demo: revisa los documentos de todas las áreas de la
organización y además crea los suyos propios).

## El ciclo de un documento

```
Borrador ──► [En revisión] ──► En aprobación ──► Aprobado ──► Archivado
   ▲               │                 │
   └── Devuelto ────┴── Devuelto ─────┘
```

- **Borrador**: el área lo está redactando. Solo ella puede modificarlo
  (revisión y jefatura también pueden, si hace falta).
- **En revisión** (solo en los tipos que pasan por revisión): revisión
  completa los datos oficiales — los que no le corresponden al área (número
  de expediente, número de resolución, órgano firmante…). El área ya no puede
  tocarlo en este punto; la ficha indica «Lo tiene revisión».
- **En aprobación**: la jefatura de servicio decide. El documento está
  bloqueado para área y revisión («Lo tiene la jefatura de servicio»).
- **Aprobado**: publicado. Ya no se puede editar; solo se consulta y se
  descarga (PDF, ODT o DOCX).
- **Archivado**: movimiento de administración desde wp-admin, para cuando el
  documento aprobado deja de estar vigente.

Si un tipo de documento **no** pasa por revisión, va directo de **Borrador**
a **En aprobación** — se salta el paso intermedio.

### Devolver un documento

En cualquier paso de envío, quien recibe el documento puede devolverlo al
paso anterior si falta algo o hay que corregir algo. Devolver siempre exige
escribir el motivo: sin motivo no se puede devolver. El documento vuelve al
estado del que salió (por ejemplo, la jefatura devuelve un «En aprobación» a
«En revisión» o directamente al área) y muestra un aviso «Devuelto por… el
[fecha]: «[motivo]»» hasta que se vuelve a enviar. El motivo también llega
por correo a quien tiene que corregir.

### Quién puede hacer qué, exactamente

La tabla completa de transiciones — de qué estado a qué estado, para qué rol,
si hace falta motivo — vive en una única tabla de datos en el código
(`Documentate_Transitions::rules()`, `includes/class-documentate-transitions.php`).
Es la fuente única de la verdad: tanto la aplicación (`/documentate/`) como
wp-admin la consultan para decidir qué botones mostrar y qué guardados
aceptar. Si algo de este documento y el comportamiento real de la aplicación
no coinciden, manda esa tabla — y hay que corregir aquí, no allí.

## Dónde se hace cada cosa

- **Aplicación** (`/documentate/`): pensada para el trabajo diario de las
  tres personas. La cabecera muestra quién ha entrado (nombre, rol y ámbito,
  con «Salir»). Bandejas según el rol ("Mis documentos" para el área;
  "Documentos" y "Para revisar" para revisión; "Documentos" y "Para aprobar"
  para la jefatura), ficha del documento con el histórico de actividad,
  edición con los campos agrupados por rol, adjuntar el fichero fuente,
  previsualizar y descargar.
- **wp-admin**: mismas acciones disponibles desde la pantalla clásica de
  entradas, para quien prefiere ese flujo o necesita archivar/desarchivar
  (esas dos acciones solo están en wp-admin).

## Actividad

Cada documento lleva un registro de lo que le ha pasado — quién lo creó, cada
envío, cada devolución con su motivo, cada aprobación — más los comentarios
que deje cualquiera de los roles. Se ve en la ficha del documento, tanto
en la aplicación como en wp-admin.

## Probarlo

`make capturas` recorre el ciclo completo con un navegador real (escritorio y
móvil, cada rol) y genera un informe con capturas en
`capturas/informe.html`. El [Playground de WordPress](../README.md#demo)
también trae documentos de ejemplo en cada estado, listos para explorarlos sin
instalar nada.
