# Publicar Peer Review Studio en Moodle Marketplace

Todo lo que hay que rellenar al enviar el plugin, listo para copiar. Los textos
de los campos están en inglés porque Marketplace los pide en inglés; las
explicaciones están en español.

Fuentes (documentación oficial de Moodle Marketplace, leída el 12/11/2026):
"Plugin submission guidelines", "Submit a plugin", "Set up and publish your
plugin page", "Quick guide to listing a plugin" y "Manage your plugin". El
formulario de envío está detrás del inicio de sesión y no he podido verlo, así
que los nombres exactos de los campos pueden variar; el contenido está
organizado según lo que esa documentación dice que se pide.

## 1. Antes de enviar

Estos puntos bloquean la aprobación o el envío si no se cumplen.

1. **Repositorio público**: hecho el 13/11/2026. Las pautas exigen un gestor
   de incidencias público y accesible; el de GitHub ya lo es.
2. **Código en GitHub y CI en verde**: hecho. El flujo "Moodle Plugin CI"
   pasa en las diez combinaciones (Moodle 4.5 a 5.3, PostgreSQL y MariaDB).
   Marketplace usa la misma herramienta (moodle-plugin-ci) en sus
   comprobaciones automáticas.
3. **Crear la cuenta de Moodle Marketplace** en <https://marketplace.moodle.com>.
   No sirve la cuenta de moodle.org; hay que registrarse con el mismo correo
   para que se enlacen. Quien envía el plugin queda como mantenedor principal y
   hoy no se puede cambiar, así que conviene enviarlo desde la cuenta que vaya
   a gestionarlo.
4. **Probar la IA una vez con el proveedor real del sitio**: Administración del
   sitio > Extensiones > Módulos de actividad > Peer Review Studio > "test AI
   review". Es una petición mínima.
5. Etiquetar la versión en Git: `v1.0.0`.

## 2. Datos del plugin

| Campo | Valor |
| --- | --- |
| Free or paid | **Free** |
| Plugin type | Activity module (`mod`) |
| Frankenstyle component name | `mod_peerreviewstudio` |
| Plugin name | Peer Review Studio |
| Plugin ZIP | `mod_peerreviewstudio_v1.0.0.zip` (carpeta `peerreview`, junto al repositorio) |
| Licence | GNU GPL v3 or later (el ZIP incluye el fichero `LICENSE`) |
| Source code URL | <https://github.com/jmolpea/moodle-mod_peerreviewstudio> |
| Issue/bug tracker URL | <https://github.com/jmolpea/moodle-mod_peerreviewstudio/issues> |
| Documentation URL | <https://github.com/jmolpea/moodle-mod_peerreviewstudio#readme> |
| Supported Moodle versions | 4.5, 5.0, 5.1, 5.2, 5.3 (se leen de `version.php`) |
| Version (build) | 2026111305 (se lee de `version.php`) |
| Release name | 1.0.0 (se lee de `version.php`) |
| Maturity | Stable (se lee de `version.php`) |

Es gratuito según la definición de Marketplace: se usa entero sin pagar nada al
autor. La IA opcional depende del proveedor de IA que el propio sitio tenga
configurado, y eso está permitido en un plugin gratuito.

## 3. Short description

Máximo 270 caracteres. Las tarjetas del buscador solo muestran los primeros
119, así que lo importante va al principio. Este texto tiene 250.

```
Structured peer review: learners submit work, review classmates with a rubric and get anonymous feedback and a grade. Automatic reviewer matching and reminders, a tutor dashboard, and an optional AI second review through the AI subsystem of the site.
```

## 4. Description

Máximo 7.000 caracteres, con formato. El texto está en
`docs/marketplace-description.html` (unos 5.500 caracteres visibles): ábrelo en
el navegador, selecciona todo, copia y pega en el campo; conserva títulos,
listas y enlaces.

Cubre lo que las pautas piden que aparezca en la descripción: qué hace y para
quién, funciones, requisitos e instalación, dependencias, servicios externos y
cómo se obtienen las credenciales, datos personales que trata, primeros pasos y
dónde está la documentación.

## 5. Screenshots

Marketplace pide entre 128 y 480 px de ancho. Están en `docs/screenshots/`, a
480 x 900 px, tomadas en Moodle 5.3 con datos ficticios:

| Fichero | Qué muestra |
| --- | --- |
| `01-learner-overview.png` | Vista del alumno: fase, siguiente paso y su entrega |
| `02-review-work.png` | El trabajo de un compañero que el alumno tiene que revisar |
| `03-review-rubric.png` | El formulario de revisión con la rúbrica |
| `04-feedback-and-grade.png` | Feedback anónimo recibido y desglose de la nota |
| `05-tutor-dashboard.png` | Panel del tutor con los contadores |
| `06-tutor-participants.png` | Lista de participantes con su estado |
| `07-tutor-submission-detail.png` | Página de una entrega: notas, nota manual y revisiones |
| `08-ai-review-notice.png` | Aviso de revisión con IA y aceptación de la política de IA |

## 6. Release notes (versión 1.0.0)

```
First public release.

- Peer review activity with three ways to run it (scheduled, manual, self-paced), automatic reviewer matching, rubric reviews, and anonymous feedback.
- Automatic follow-up: reminders, replacement of reviewers who do not respond, and a message to the tutors when a submission is left without a review.
- Tutor dashboard and a page per submission to assign a reviewer by hand, match again, or set the final grade.
- Gradebook, completion rules, calendar and timeline deadlines, groups, course reset, backup and restore, events, and the Privacy API.
- Optional AI second review through the Moodle AI subsystem. The plugin has no API key of its own. A learner's work is only sent to the AI once that learner has accepted the site AI policy, and the "AI tools" course setting is respected.
- Tested with PHPUnit and Behat on Moodle 4.5 to 5.3, with PostgreSQL and MariaDB.

Upgrading from 0.9.x: the plugin's own AI provider, API key, and model settings are removed. Set up an AI provider in Site administration > General > AI > AI providers to keep using AI review.
```

El historial completo está en `CHANGES.md`.

## 7. Servicios externos y credenciales

Las pautas piden declarar cualquier servicio de terceros, explicar qué depende
de él y cómo se consiguen las credenciales, y dar a los revisores lo necesario
para probarlo. Ya está en la descripción; si el formulario tiene un campo
aparte, este es el texto:

```
The plugin calls no external service itself and stores no credentials. The optional AI second review uses the Moodle AI subsystem (core_ai, "Generate text" action), so it needs an AI provider enabled in Site administration > General > AI > AI providers. Depending on the provider, the institution needs its own account or API key with that provider, obtained from the provider and entered in that page. Without an AI provider every other feature works; only the AI second review is unavailable. No payment to the plugin author is involved.
```

## 8. Nota para los revisores

Para el ticket de revisión o el campo de comentarios del envío:

```
No credentials are needed to review this plugin.

Quick test: create a Peer Review Studio activity in "Open (self-paced)" mode with 1 review required and 1 review received per submission. Two students submit; the second one is matched with the first at once, reviews it with the rubric, and the first student then reads the anonymous feedback and the grade. The teacher sees the dashboard and the page of each submission. In scheduled mode, matching, reminders, and the release of feedback run in the scheduled task "Match reviewers and follow up pending peer reviews" (every 10 minutes), so cron must run.

AI review is optional and goes through core_ai only (generate_text). To test it, enable any AI provider in the test site; the plugin has no provider settings. Site administration > Plugins > Activity modules > Peer Review Studio shows whether a provider is available and links to a page that sends one small test request. A learner's content is only sent once that learner has accepted the site AI policy (the activity shows the policy and an "Accept and continue" button).

Automated tests: 64 PHPUnit tests and 4 Behat scenarios; .github/workflows/ci.yml runs moodle-plugin-ci on Moodle 4.5 to 5.3 with PostgreSQL and MariaDB.

The package contains English strings only. A Spanish translation is kept in the repository (lang/es) and will be contributed through AMOS.
```

## 9. Si el formulario pide categorías o palabras clave

```
peer review, peer assessment, rubric, feedback, formative assessment, self-paced, collaboration, AI
```

## 10. Qué comprueba Marketplace y cómo está el plugin

| Requisito de las pautas | Estado |
| --- | --- |
| Instalación desde ZIP sin pasos extra ni Composer | Cumple |
| Sin avisos de PHP con depuración de desarrollador | Cumple (probado en navegador en 5.3) |
| MySQL/MariaDB y PostgreSQL | Cumple (tests en ambos) |
| Estilo de código, PHPDoc, CSS (moodle-plugin-ci) | 0 errores y 0 avisos, en local y en GitHub Actions |
| Cabecera GPL y `@copyright` en todos los ficheros | Cumple |
| CSS con selectores acotados al plugin | Cumple (`.path-mod-peerreviewstudio ...`) |
| Nombres con prefijo del componente | Cumple |
| Ajustes en `config_plugins` | Cumple |
| Solo cadenas en inglés dentro del paquete | Cumple (`lang/es` no entra en el ZIP) |
| API de privacidad, incluido el envío a la IA | Cumple |
| `sesskey`, `require_login`, capacidades, SQL con parámetros | Cumple |
| Copia de seguridad y restauración (obligatorio en actividades) | Cumple |
| Fichero de licencia en el paquete | Cumple (`LICENSE`) |
| Gestor de incidencias público | Cumple (incidencias de GitHub, repositorio público) |
| Sin pagos ni publicidad fuera de Marketplace | Cumple |

## 11. Después de la aprobación

- El plugin no se publica solo: cuando pase la revisión hay que repasar la
  página y pulsar publicar.
- Subir la traducción al español a AMOS (<https://lang.moodle.org>) a partir
  de `lang/es/peerreviewstudio.php`. Hasta entonces, quien instale el ZIP verá
  el plugin en inglés.
- Las versiones nuevas se suben a mano desde "Edit plugin page > Versions".
