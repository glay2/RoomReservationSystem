<?php
// database/setup.php - Database installer and seed data reset tool

$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';
$dbName = 'room_reservation_system';

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || php_sapi_name() === 'cli') {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `$dbName`;");

        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($sql);

        $message = "Database '$dbName' and all seed tables/data successfully installed!";
        if (php_sapi_name() === 'cli') {
            echo "$message\n";
            exit(0);
        }
    } catch (Exception $e) {
        $error = "Installation Error: " . $e->getMessage();
        if (php_sapi_name() === 'cli') {
            echo "$error\n";
            exit(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Reservation System | Database Setup</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
    <div class="card" style="max-width:540px; width:100%; text-align:center;">
        <div style="font-size:2.5rem; margin-bottom:12px;">⚙️</div>
        <h1 style="font-size:1.4rem; margin-bottom:8px;">Database Setup & Reset Tool</h1>
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:20px;">
            Initialize or restore the complete <strong>room_reservation_system</strong> database schema, sample rooms, administrator accounts, and reservation audit logs.
        </p>

        <?php if (!empty($message)): ?>
            <div class="flash-message flash-success" style="margin-bottom:20px;">
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <a href="../index.php" class="btn btn-primary" style="width:100%;">Proceed to Sign In</a>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="flash-message flash-error" style="margin-bottom:20px;">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px;">
                    Initialize / Reset Database Now
                </button>
            </form>
            <div style="margin-top:14px;">
                <a href="../index.php" style="font-size:0.85rem; color:var(--text-muted);">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
