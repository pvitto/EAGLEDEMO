<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/send_email.php';

// --- LÓGICA DE NOTIFICACIONES RECURRENTES DE TAREAS ---
// Obtener todas las tareas manuales que tienen un intervalo de notificación.
$recurrent_tasks_sql = "
    SELECT id, title, instruction, priority, assigned_to_user_id, assigned_to_group,
           notification_interval, last_notification_sent, created_by_user_id
    FROM tasks
    WHERE type = 'Manual' AND status = 'Pendiente' AND notification_interval IS NOT NULL AND notification_interval > 0
";
$recurrent_tasks_result = $conn->query($recurrent_tasks_sql);

if ($recurrent_tasks_result) {
    while ($task = $recurrent_tasks_result->fetch_assoc()) {
        $now = new DateTime();
        $last_sent = $task['last_notification_sent'] ? new DateTime($task['last_notification_sent']) : null;
        $interval_minutes = (int)$task['notification_interval'];

        // Si nunca se ha enviado o si ya pasó el intervalo desde el último envío
        if (!$last_sent || $now->getTimestamp() - $last_sent->getTimestamp() >= $interval_minutes * 60) {

            // 1. Obtener los destinatarios (usuarios)
            $recipients = [];
            if ($task['assigned_to_group']) {
                $stmt_users = $conn->prepare("SELECT u.email, u.name FROM users u JOIN user_groups ug ON u.id = ug.user_id WHERE ug.group_name = ? AND u.email IS NOT NULL AND u.email != ''");
                $stmt_users->bind_param("s", $task['assigned_to_group']);
                $stmt_users->execute();
                $result_users = $stmt_users->get_result();
                while ($user_row = $result_users->fetch_assoc()) {
                    $recipients[] = $user_row;
                }
                $stmt_users->close();
            } elseif ($task['assigned_to_user_id']) {
                $stmt_user = $conn->prepare("SELECT email, name FROM users WHERE id = ? AND email IS NOT NULL AND email != ''");
                $stmt_user->bind_param("i", $task['assigned_to_user_id']);
                $stmt_user->execute();
                $result_user = $stmt_user->get_result();
                if ($user_row = $result_user->fetch_assoc()) {
                    $recipients[] = $user_row;
                }
                $stmt_user->close();
            }

            // 2. Enviar correo a los destinatarios
            if (!empty($recipients)) {
                $creator_name_result = $conn->query("SELECT name FROM users WHERE id = " . (int)$task['created_by_user_id']);
                $creator_name = $creator_name_result->fetch_assoc()['name'] ?? 'el sistema';

                $email_subject = "Recordatorio de Tarea Manual: " . htmlspecialchars($task['title']);
                $email_body = "<h1>Recordatorio de Tarea Asignada</h1>" .
                              "<p>Esta es una notificación recurrente para la tarea creada por <strong>" . htmlspecialchars($creator_name) . "</strong>.</p><hr>" .
                              "<h2>Detalles de la Tarea</h2><ul>" .
                              "<li><strong>Título:</strong> " . htmlspecialchars($task['title']) . "</li>" .
                              "<li><strong>Instrucción:</strong> " . (!empty($task['instruction']) ? nl2br(htmlspecialchars($task['instruction'])) : 'N/A') . "</li>" .
                              "<li><strong>Prioridad:</strong> " . htmlspecialchars($task['priority']) . "</li>" .
                              "</ul><p>Por favor, revisa la plataforma para más detalles.</p>";

                foreach ($recipients as $recipient) {
                    send_task_email($recipient['email'], $recipient['name'], $email_subject, $email_body);
                }
            }

            // 3. Actualizar la fecha del último envío en la base de datos
            $stmt_update = $conn->prepare("UPDATE tasks SET last_notification_sent = NOW() WHERE id = ?");
            $stmt_update->bind_param("i", $task['id']);
            $stmt_update->execute();
            $stmt_update->close();
        }
    }
}


// --- Cargar datos iniciales ---
$all_users = [];
$users_result = $conn->query("SELECT id, name, role, email, gender FROM users ORDER BY name ASC");
if ($users_result) { while ($row = $users_result->fetch_assoc()) { $all_users[] = $row; } }
$admin_users_list = ($_SESSION['user_role'] === 'Admin') ? $all_users : [];

// --- Lógica de Usuario y Rol ---
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$current_user_gender = $_SESSION['user_gender'] ?? null;

function getRoleDisplayName($role, $gender) {
    if ($gender === 'F') {
        switch ($role) {
            case 'Digitador': return 'Digitadora';
            case 'Operador': return 'Operadora';
            case 'Checkinero': return 'Checkinera';
            default: return $role;
        }
    }
    return $role;
}
$displayRole = getRoleDisplayName($current_user_role, $current_user_gender);

// Clases CSS para roles
$role_color_class = 'bg-gray-200 text-gray-800'; // Color del badge interno
$role_nav_class = 'nav-admin'; // Clase para la navegación
$role_container_border_class = 'border-gray-400'; // Borde del contenedor de saludo (default)
$role_container_bg_class = 'bg-gray-50'; // Fondo del contenedor de saludo (default)

switch ($current_user_role) {
    case 'Admin':
        $role_color_class = 'bg-red-200 text-red-800'; $role_nav_class = 'nav-admin';
        $role_container_border_class = 'border-red-400'; $role_container_bg_class = 'bg-red-50'; break;
    case 'Digitador':
        $role_color_class = 'bg-blue-200 text-blue-800'; $role_nav_class = 'nav-digitador';
        $role_container_border_class = 'border-blue-400'; $role_container_bg_class = 'bg-blue-50'; break;
    case 'Operador':
        $role_color_class = 'bg-yellow-200 text-yellow-800'; $role_nav_class = 'nav-operador';
        $role_container_border_class = 'border-yellow-400'; $role_container_bg_class = 'bg-yellow-50'; break;
    case 'Checkinero':
        $role_color_class = 'bg-green-200 text-green-800'; $role_nav_class = 'nav-checkinero';
        $role_container_border_class = 'border-green-400'; $role_container_bg_class = 'bg-green-50'; break;
}

// --- LÓGICA DE ALERTAS Y TAREAS PENDIENTES ---
$all_pending_items = [];
// ... (resto de la lógica de PHP sin cambios) ...

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <!-- ... (head sin cambios) ... -->
</head>
<body class="bg-white text-gray-800">

    <!-- ... (modales y popups sin cambios) ... -->

    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-full mx-auto">
        <!-- ... (header sin cambios) ... -->

        <main>
            <div id="content-operaciones">
                 <!-- ... (Panel General sin cambios) ... -->
                 <div class="space-y-8">
                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Crear Tarea Manual</h2>
                            <form id="manual-task-form" class="space-y-3">
                                <div><label for="manual-task-title" class="text-sm font-medium">Título</label><input type="text" id="manual-task-title" required class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                <div><label for="manual-task-desc" class="text-sm font-medium">Descripción</label><textarea id="manual-task-desc" rows="3" class="w-full p-2 text-sm border rounded-md mt-1"></textarea></div>
                                <div><label for="manual-task-priority" class="text-sm font-medium">Prioridad</label><select id="manual-task-priority" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="Alta">Alta</option><option value="Media" selected>Media</option><option value="Baja">Baja</option></select></div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div><label for="manual-task-start" class="text-sm font-medium">Inicio</label><input type="datetime-local" id="manual-task-start" class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                    <div><label for="manual-task-end" class="text-sm font-medium">Fin</label><input type="datetime-local" id="manual-task-end" class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                </div>
                                <div>
                                    <label for="notification_interval" class="text-sm font-medium">Intervalo de Notificación (minutos)</label>
                                    <input type="number" id="notification_interval" min="1" class="w-full p-2 text-sm border rounded-md mt-1" placeholder="Ej: 60 para cada hora">
                                </div>
                                <div><label for="manual-task-user" class="text-sm font-medium">Asignar a</label><select id="manual-task-user" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="">Seleccionar...</option><optgroup label="Grupos"><option value="group-todos">Todos</option><option value="group-Operador">Operadores</option><option value="group-Checkinero">Checkineros</option><option value="group-Digitador">Digitadores</option></optgroup><optgroup label="Individuales"><?php foreach ($all_users as $user):?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)</option><?php endforeach; ?></optgroup></select></div>
                                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 mt-4 rounded-md">Crear Tarea</button>
                            </form>
                        </div>
                        <!-- ... (resto del panel general) ... -->
                 </div>
            </div>

            <div id="content-operador" class="hidden">
                 <h2 class="text-2xl font-bold text-gray-900 mb-6">Módulo de Operador</h2>
                 <div id="consultation-section" class="bg-white p-6 rounded-xl shadow-lg mb-8">
                     <!-- ... (formulario de consulta) ... -->
                </div>
                <div id="operator-panel" class="hidden"><div class="bg-white p-6 rounded-xl shadow-lg mb-8 relative">
                    <!-- ... (detalles de la planilla) ... -->
                    <h3 class="text-xl font-semibold mb-4">Detalle de Denominación</h3>
                    <form id="denomination-form"><input type="hidden" id="op-checkin-id">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="table_number" class="block text-sm font-medium text-gray-700 mb-1">Nro de Mesa</label>
                                <input type="number" id="table_number" name="table_number" required class="w-full px-3 py-2 border border-gray-300 rounded-md">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <!-- ... (filas de denominaciones) ... -->
                        </div>
                        <div class="mt-6"><label for="observations" class="block text-sm font-medium">Observación</label><textarea id="observations" rows="3" class="mt-1 w-full border rounded-md p-2"></textarea></div>
                        <div class="mt-6 flex justify-end"><button type="submit" class="bg-green-600 text-white font-bold py-3 px-6 rounded-md hover:bg-green-700">Guardar y Cerrar</button></div>
                        <!-- ... (overlay de carga) ... -->
                    </form>
                </div></div>
                <!-- ... (resto del panel operador) ... -->
            </div>

            <!-- ... (resto de los paneles sin cambios) ... -->
        </main>
    </div>

    <script>
    // ... (todo el JavaScript inicial sin cambios) ...

    document.getElementById('manual-task-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const title = document.getElementById('manual-task-title').value;
        const instruction = document.getElementById('manual-task-desc').value;
        const selectedValue = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        const start_datetime = document.getElementById('manual-task-start').value;
        const end_datetime = document.getElementById('manual-task-end').value;
        const notification_interval = document.getElementById('notification_interval').value;

        if (!selectedValue) { alert('Selecciona un usuario o grupo.'); return; }
        if (start_datetime && end_datetime && start_datetime >= end_datetime) { alert('La fecha de fin debe ser posterior a la fecha de inicio.'); return; }

        let payload = { title, instruction, type: 'Manual', priority, start_datetime: start_datetime || null, end_datetime: end_datetime || null, notify_by_email: true, notification_interval: notification_interval || null };
        if (selectedValue.startsWith('group-')) {
             payload.assign_to_group = selectedValue.replace('group-', '');
        } else {
             payload.assign_to = selectedValue;
        }

        await sendTaskRequest(payload);
    });

    async function handleDenominationSave(event) {
        event.preventDefault();
        const tableNumberInput = document.getElementById('table_number');
        if (!tableNumberInput.value) {
            alert('El número de mesa es obligatorio.');
            tableNumberInput.focus();
            return;
        }

        const loadingOverlay = document.getElementById('loading-overlay');
        loadingOverlay.classList.remove('hidden');

        animateProgressBar(async () => {
            const payload = {
                check_in_id: document.getElementById('op-checkin-id').value,
                table_number: tableNumberInput.value, // Añadido
                bills_100k: parseInt(document.querySelector('#denomination-form [data-value="100000"] .denomination-qty').value) || 0,
                bills_50k: parseInt(document.querySelector('#denomination-form [data-value="50000"] .denomination-qty').value) || 0,
                bills_20k: parseInt(document.querySelector('#denomination-form [data-value="20000"] .denomination-qty').value) || 0,
                bills_10k: parseInt(document.querySelector('#denomination-form [data-value="10000"] .denomination-qty').value) || 0,
                bills_5k: parseInt(document.querySelector('#denomination-form [data-value="5000"] .denomination-qty').value) || 0,
                bills_2k: parseInt(document.querySelector('#denomination-form [data-value="2000"] .denomination-qty').value) || 0,
                coins: parseFloat(document.getElementById('coins-value').value) || 0,
                total_counted: 0,
                discrepancy: 0,
                observations: document.getElementById('observations').value
            };
            // ... (resto de la función handleDenominationSave sin cambios) ...
        });
    }

    // ... (resto del JavaScript sin cambios) ...
    </script>
</body>
</html>