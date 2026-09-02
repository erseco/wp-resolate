# Flujo de documentos y roles

Guía funcional del ciclo de vida de un documento en Documentate: quién hace
qué, en qué orden y qué pasa cuando algo se devuelve. Pensada para el equipo
(no es una referencia de código; para eso está `ARCHITECTURE.md`).

## Los tres roles

| Rol | Quién | Qué hace |
|---|---|---|
| **Área** | Cualquier persona con permiso de edición que no sea gestión ni administración (cuenta de demostración: `author1`) | Crea el documento, rellena sus propios datos, lo envía y solo ve los documentos de su propio ámbito |
| **Gestión documental** | Cuentas con el rol «Gestión documental» (cuenta de demostración: `editor1`) | Completa los datos oficiales de los documentos de **cualquier** área que hayan entrado en el circuito, y los pasa a administración |
| **Administración** | Administradores del sitio (cuenta de demostración: `admin`) | Aprueba y publica, o devuelve con un motivo; también archiva y desarchiva |

Una misma persona puede ser gestión documental y, a la vez, área para su
propio ámbito (así es `editor1` en la demo: gestiona los documentos de todas
las áreas y además crea los suyos propios como «Subdirección de
Administración»).

## El ciclo de un documento

```
Borrador ──► [En gestión] ──► En revisión ──► Aprobado ──► Archivado
   ▲               │                │
   └── Devuelto ────┴── Devuelto ────┘
```

- **Borrador**: el área lo está redactando. Solo ella puede modificarlo.
- **En gestión** (solo en los tipos que pasan por gestión documental):
  gestión completa los datos oficiales — los que no le corresponden al área
  (número de expediente, número de resolución, órgano firmante…). El área ya
  no puede tocarlo en este punto.
- **En revisión**: administración decide. El documento está bloqueado para
  área y gestión.
- **Aprobado**: publicado. Ya no se puede editar; solo se consulta y se
  descarga (PDF, ODT o DOCX).
- **Archivado**: movimiento de administración desde wp-admin, para cuando el
  documento aprobado deja de estar vigente.

Si un tipo de documento **no** pasa por gestión documental, va directo de
**Borrador** a **En revisión** — se salta el paso intermedio.

### Devolver un documento

En cualquier paso de envío, quien recibe el documento puede devolverlo al
paso anterior si falta algo o hay que corregir algo. Devolver siempre exige
escribir el motivo: sin motivo no se puede devolver. El documento vuelve al
estado del que salió (por ejemplo, administración devuelve un «En revisión» a
«En gestión» o directamente al área) y muestra un aviso «Devuelto por… el
[fecha]: «[motivo]»» hasta que se vuelve a enviar. El motivo también llega
por correo a quien tiene que corregir.

### Quién puede hacer qué, exactamente

La tabla completa de transiciones — de qué estado a qué estado, para qué rol,
si hace falta motivo — vive en una única tabla de datos en el código
(`Documentate_Transiciones::reglas()`, `includes/class-documentate-transiciones.php`).
Es la fuente única de la verdad: tanto la aplicación (`/documentate/`) como
wp-admin la consultan para decidir qué botones mostrar y qué guardados
aceptar. Si algo de este documento y el comportamiento real de la aplicación
no coinciden, manda esa tabla — y hay que corregir aquí, no allí.

## Dónde se hace cada cosa

- **Aplicación** (`/documentate/`): pensada para el trabajo diario de las
  tres personas. Bandejas ("Mis documentos", "Para revisar"), ficha del
  documento con el histórico de actividad, edición con los campos agrupados
  por rol, adjuntar el fichero fuente, previsualizar y descargar.
- **wp-admin**: mismas acciones disponibles desde la pantalla clásica de
  entradas, para quien prefiere ese flujo o necesita archivar/desarchivar
  (esas dos acciones solo están en wp-admin).

## Actividad

Cada documento lleva un registro de lo que le ha pasado — quién lo creó, cada
envío, cada devolución con su motivo, cada aprobación — más los comentarios
que deje cualquiera de los tres roles. Se ve en la ficha del documento, tanto
en la aplicación como en wp-admin.

## Probarlo

`make capturas` recorre el ciclo completo con un navegador real (escritorio y
móvil, los tres roles) y genera un informe con capturas en
`capturas/informe.html`. El [Playground de WordPress](../README.md#demo)
también trae documentos de ejemplo en cada estado, listos para explorarlos sin
instalar nada.
