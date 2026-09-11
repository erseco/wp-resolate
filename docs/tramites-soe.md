# Trámites SOE y modelos de Documentate

Correspondencia entre la *Guía rápida - Trámites SOE* del Servicio y los tipos
de documento que trae el plugin. Sirve para saber, ante un trámite, si hay
modelo, y para no volver a discutir por qué algunos trámites no lo tienen.

No es una referencia de código; para el ciclo de vida está
`docs/flujo-documentos.md` y para los campos por rol,
`docs/campos-por-rol.md`.

Las plantillas de `fixtures/` se contrastaron campo a campo con los modelos
oficiales del Servicio (carpeta *MODELOS_SOLICITUDES_PROTOCOLOS_INFORMA*).
Ninguna añade contenido que el modelo oficial no tenga: lo que la guía pide
se dice en la ayuda del campo o del tipo, no en el cuerpo del documento.

## Qué trámite usa qué modelo

| Trámite de la guía | Tipo de documento |
|---|---|
| Solicitudes de desplazamiento propias | `solicitud-desplazamiento-dg` |
| Solicitudes de desplazamiento de externos | `solicitud-desplazamiento-externo` |
| Respuestas a escritos, sugerencias y reclamaciones | `respuesta-escrito` |
| Oficios que se envían por registro | `oficio` |
| Invitaciones del área a jornadas | `invitacion` |
| Convocatorias de reunión | `convocatoria-reunion` |
| Memoria previa a resoluciones de proyectos o programas | `memoria-previa-resolucion` |
| Resoluciones de proyectos y programas | `resolucion-administrativa` |
| Documento 0 | `propuesta-gasto` |
| Resoluciones de libramiento | `libramiento-ceps`, `libramiento-centros` |
| Solicitud de envío de masivos | `correo-masivo` |
| Informes posteriores a reuniones con externos | `modelo-informe` |
| Memoria de pago con dinero del CEP | `memoria-pago` |
| Asistencia del personal del área a eventos y jornadas | `autorizacion-viaje` |

Tres modelos del plugin no salen en la guía porque no son trámites del
circuito: `gastos-suplidos`, `respuesta-parlamentaria` y `hace-constar`.

## Trámites que no tienen —ni deben tener— modelo

Son gestiones de correo, de Sicho o de sede electrónica, no documentos que se
redacten y se firmen aquí:

- **Solicitudes Sicho**, que se piden en Sicho y se avisan por correo.
- **Publicaciones web**, que siguen su propio protocolo una vez la resolución
  está firmada y registrada.
- **Comunicaciones de indisposición** o de falta de asistencia sobrevenida.
- **Parte de accidente**, que tiene su procedimiento propio.
- **Baja laboral**, que se tramita por sede electrónica.

> **Protección de datos.** La guía es explícita con la baja laboral: el parte
> de baja y el diagnóstico no se envían al Servicio. Si alguna vez se añadiera
> un modelo o un adjunto para este trámite, el diagnóstico tiene que ir
> borrado. Es la razón por la que no hay modelo, no un olvido.

## Dos modelos que se incorporaron desde la carpeta oficial

- **`oficio`**, de `Modelo_oficio_enviar_por_registro.odt`: mismo encabezado
  que la respuesta a escrito, pero sin número de registro de entrada y con
  el cuerpo «Se adjunta… de esta Dirección General». Por eso
  `respuesta-escrito` conserva su número de registro como obligatorio: no
  tiene que estirarse para cubrir el oficio, que tiene modelo propio.
- **`invitacion`**, de `Modelo_invitaciones.odt`: destinatario, asunto,
  cuerpo de la invitación y el resumen esquemático de lugar, fecha, horario,
  programa e información. El modelo oficial lleva una imagen marcada como
  opcional; la plantilla no la incluye, y quien la necesite la adjunta.
  La frase del cuerpo dice «que organiza la Dirección General» en vez del
  «organizadas por» del ejemplo, que solo concuerda con unas jornadas.

## Trámites de la guía que todavía no tienen modelo

- **Comunicaciones a centros, CEP y similares** («seguir modelo expreso»),
  cuyo modelo no está identificado con seguridad en la carpeta oficial.
- **Informe para permisos de mentorías** («modelo de informe en gomera»), que
  firma el Responsable del Servicio para que la persona lo presente en la
  territorial, y que tampoco está en la carpeta oficial.

## Reglas de la guía y dónde viven

Las observaciones de la guía son indicaciones, no contenido de los documentos:
ninguna añade texto al modelo oficial. Están en la descripción del tipo —que
la app muestra al elegirlo— o en la descripción del campo al que afectan:

| Regla de la guía | Dónde está |
|---|---|
| El Documento 0 se presenta con 40 días de antelación | Descripción del tipo `propuesta-gasto` |
| El masivo lo autoriza el Responsable del Servicio | Descripción del tipo y del campo `autorizado` |
| La memoria de pago del CEP es excepcional | Descripción del tipo `memoria-pago` |
| El número y la fecha de la resolución los pone el Responsable del Servicio | Descripción de `resolucion_num` y `resolucion_fecha`, que ya no son obligatorios |
| Hay que justificar la pernocta o el exceso del horario de 7:00 a 16:00 | Descripción de `motivo` (propias) y de `horario` y `personas.observaciones` (externos) |
| En los actos voluntarios no se contempla el exceso de horas | Descripción del tipo `autorizacion-viaje` |
| El masivo con listado concreto de centros va con un `.txt` aparte | Descripción de `observaciones_centros` |
| Los documentos se nombran de forma reconocible, con el área | Nombre con el que se descargan: prefijo, título y área |
| Todo documento va con logotipos, márgenes y letra correctos | Lo pone Documentate; se dice en la ayuda del formulario |
