<?php
require '../config.php';
require '../db_connection.php'; // Asegúrate que esta ruta sea correcta
require_once '../send_email.php'; // Incluir la nueva utilidad de correo
header('Content-Type: application/json');

// Verificar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// --- ID del usuario que realiza la acción ---
$creator_id = $_SESSION['user_id'];
$creator_name = $_SESSION['user_name'] ?? 'Un usuario'; // Nombre para usar en correos

$method = $_SERVER['REQUEST_METHOD'];

// --- Manejo DELETE para Recordatorios ---
if ($method === 'DELETE') {
    // ... (código de DELETE sin cambios) ...
}

// --- Manejo POST para Tareas y Recordatorios ---
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido: ' . json_last_error_msg()]);
        $conn->close();
        exit;
    }

    $user_id = isset($data['assign_to']) ? filter_var($data['assign_to'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $assign_to_group = $data['assign_to_group'] ?? null;
    $instruction = isset($data['instruction']) ? trim($data['instruction']) : '';
    $type = $data['type'] ?? '';
    $task_id = isset($data['task_id']) ? filter_var($data['task_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $alert_id = isset($data['alert_id']) ? filter_var($data['alert_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    $title = isset($data['title']) ? trim($data['title']) : null;
    $priority = $data['priority'] ?? 'Media';
    $start_datetime = !empty($data['start_datetime']) ? $data['start_datetime'] : null;
    $end_datetime = !empty($data['end_datetime']) ? $data['end_datetime'] : null;
    $notification_interval = isset($data['notification_interval']) ? filter_var($data['notification_interval'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null; // Nuevo campo
    $notify_by_email = true;

    // ... (resto de la lógica de recordatorio y asignación sin cambios) ...

    if ($type === 'Manual') {
        if (!$title) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'El título es requerido.']);
            $conn->close();
            exit;
        }

        $conn->begin_transaction();
        try {
            $task_id = null;
            if ($assign_to_group) {
                $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_group, type, start_datetime, end_datetime, notification_interval, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?, ?)");
                if (!$stmt) throw new Exception("Error al preparar la consulta para grupo: " . $conn->error);
                $stmt->bind_param("ssssssii", $title, $instruction, $priority, $assign_to_group, $start_datetime, $end_datetime, $notification_interval, $creator_id);
                if (!$stmt->execute()) throw new Exception("Error al crear la tarea para el grupo: " . $stmt->error);
                $task_id = $stmt->insert_id;
                $stmt->close();
            } elseif ($user_id) {
                $stmt = $conn->prepare("INSERT INTO tasks (title, instruction, priority, assigned_to_user_id, type, start_datetime, end_datetime, notification_interval, created_by_user_id) VALUES (?, ?, ?, ?, 'Manual', ?, ?, ?, ?)");
                if (!$stmt) throw new Exception("Error al preparar la consulta para usuario: " . $conn->error);
                $stmt->bind_param("sssissii", $title, $instruction, $priority, $user_id, $start_datetime, $end_datetime, $notification_interval, $creator_id);
                if (!$stmt->execute()) throw new Exception("Error al crear la tarea para el usuario: " . $stmt->error);
                $task_id = $stmt->insert_id;
                $stmt->close();
            } else {
                throw new Exception("Debe seleccionar un usuario o un grupo para asignar la tarea.");
            }

            if ($notify_by_email && $task_id) {
                $recipients = [];
                if ($assign_to_group) {
                    $stmt_users = $conn->prepare("SELECT u.email, u.name FROM users u JOIN user_groups ug ON u.id = ug.user_id WHERE ug.group_name = ? AND u.email IS NOT NULL AND u.email != ''");
                    if (!$stmt_users) throw new Exception("Error al preparar la consulta de usuarios de grupo: " . $conn->error);
                    $stmt_users->bind_param("s", $assign_to_group);
                    $stmt_users->execute();
                    $result_users = $stmt_users->get_result();
                    while ($user_row = $result_users->fetch_assoc()) {
                        $recipients[] = $user_row;
                    }
                    $stmt_users->close();
                } elseif ($user_id) {
                    $stmt_user = $conn->prepare("SELECT email, name FROM users WHERE id = ? AND email IS NOT NULL AND email != ''");
                    if (!$stmt_user) throw new Exception("Error al preparar la consulta de usuario: " . $conn->error);
                    $stmt_user->bind_param("i", $user_id);
                    $stmt_user->execute();
                    $result_user = $stmt_user->get_result();
                    if ($user_row = $result_user->fetch_assoc()) {
                        $recipients[] = $user_row;
                    }
                    $stmt_user->close();
                }

                if (!empty($recipients)) {
                    $email_subject = "Nueva Tarea Manual Asignada: " . htmlspecialchars($title);
                    $email_body = "<h1>Nueva Tarea Asignada</h1>" .
                                  "<p>Has recibido una nueva tarea manual creada por <strong>" . htmlspecialchars($creator_name) . "</strong>.</p><hr>" .
                                  "<h2>Detalles de la Tarea</h2><ul>" .
                                  "<li><strong>Título:</strong> " . htmlspecialchars($title) . "</li>" .
                                  "<li><strong>Instrucción:</strong> " . (!empty($instruction) ? nl2br(htmlspecialchars($instruction)) : 'N/A') . "</li>" .
                                  "<li><strong>Prioridad:</strong> " . htmlspecialchars($priority) . "</li>" .
                                  ($start_datetime ? "<li><strong>Fecha de Inicio:</strong> " . htmlspecialchars($start_datetime) . "</li>" : "") .
                                  ($end_datetime ? "<li><strong>Fecha de Fin:</strong> " . htmlspecialchars($end_datetime) . "</li>" : "") .
                                  "</ul><p>Por favor, revisa la plataforma para más detalles.</p><br>" .
                                  "<p><em>Este es un correo automático del sistema EAGLE 3.0.</em></p>";

                    foreach ($recipients as $recipient) {
                        send_task_email($recipient['email'], $recipient['name'], $email_subject, $email_body);
                    }
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Tarea manual creada y notificaciones enviadas correctamente.']);
            $conn->close();
            exit; // Salir después de una operación exitosa

        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            $conn->close();
            exit;
        }
    }

    // ... (resto de la lógica de ejecución del statement y envío de correo) ...
}
?>
