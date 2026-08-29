<?php
header('Content-Type: application/json');

// ============================================================
// LOAD ENVIRONMENT VARIABLES FROM .env FILE
// ============================================================

// Function to parse .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse key=value
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
            $value = substr($value, 1, -1);
        }
        
        // Set environment variable
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Load .env file from the same directory
loadEnv(__DIR__ . '/.env');

// ============================================================
// DATABASE CONNECTION - Using Environment Variables
// ============================================================

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'frenchor_users';
$username = getenv('DB_USER') ?: 'frenchor_admin';
$password = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database connection fault."]);
    exit();
}

// ============================================================
// EMAIL CONFIGURATION - Using Environment Variables
// ============================================================

$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@frenchora.co.za';
$fromEmail = getenv('FROM_EMAIL') ?: 'info@frenchora.co.za';
$siteName = getenv('SITE_NAME') ?: 'Frenchora';

// ============================================================
// PROCESS REGISTRATION FORM
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract data matching the HTML input names precisely
    $learner_name       = trim($_POST['reg-learner-name'] ?? '');
    $learner_dob        = trim($_POST['reg-dob'] ?? '');
    $learner_age        = intval($_POST['reg-age'] ?? 0);
    $learner_gender     = trim($_POST['reg-gender'] ?? null);
    $parent_name        = trim($_POST['reg-parent-name'] ?? null);
    $contact_email      = trim($_POST['reg-email'] ?? '');
    $contact_phone      = trim($_POST['reg-phone'] ?? '');
    $contact_country    = trim($_POST['reg-country'] ?? null);
    $french_level       = trim($_POST['reg-french-level'] ?? '');
    $preferred_schedule = trim($_POST['reg-schedule'] ?? null);
    $placement_score    = intval($_POST['placement-score'] ?? 0);

    // Validation Check
    if (empty($learner_name) || empty($contact_email) || empty($french_level)) {
        echo json_encode(["status" => "error", "message" => "Required registration metrics are missing."]);
        exit();
    }

    // Generate Unique Application Reference Number (YYYY-XXXXXX)
    $isUnique = false;
    $studentNumber = '';
    $year = date('Y');

    while (!$isUnique) {
        $randomDigits = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $studentNumber = "{$year}{$randomDigits}";

        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE student_number = ?");
        $checkStmt->execute([$studentNumber]);
        if ($checkStmt->fetchColumn() == 0) {
            $isUnique = true;
        }
    }

    try {
        $sql = "INSERT INTO registrations (
                    student_number, learner_name, learner_dob, learner_age, learner_gender, 
                    parent_name, contact_email, contact_phone, contact_country, 
                    french_level, preferred_schedule, placement_score, registered
                ) VALUES (
                    :student_number, :learner_name, :learner_dob, :learner_age, :learner_gender, 
                    :parent_name, :contact_email, :contact_phone, :contact_country, 
                    :french_level, :preferred_schedule, :placement_score, 0
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':student_number'     => $studentNumber,
            ':learner_name'       => $learner_name,
            ':learner_dob'        => $learner_dob,
            ':learner_age'        => $learner_age,
            ':learner_gender'     => $learner_gender,
            ':parent_name'        => $parent_name,
            ':contact_email'      => $contact_email,
            ':contact_phone'      => $contact_phone,
            ':contact_country'    => $contact_country,
            ':french_level'       => $french_level,
            ':preferred_schedule' => $preferred_schedule,
            ':placement_score'    => $placement_score
        ]);

        // ============================================================
        // SEND EMAIL NOTIFICATION
        // ============================================================

        $subject = "New $siteName Registration - " . $studentNumber;

        $message = "A new learner registration has been submitted.\n\n";
        $message .= "Reference Number: " . $studentNumber . "\n";
        $message .= "Learner Name: " . $learner_name . "\n";
        $message .= "Parent/Guardian: " . ($parent_name ?: 'N/A (Adult learner)') . "\n";
        $message .= "Email: " . $contact_email . "\n";
        $message .= "Phone: " . $contact_phone . "\n";
        $message .= "Country: " . ($contact_country ?: 'Not specified') . "\n";
        $message .= "French Level: " . $french_level . "\n";
        $message .= "Preferred Schedule: " . ($preferred_schedule ?: 'Not specified') . "\n\n";
        $message .= "Please log in to the Frenchora Admin Portal to review the registration.\n";
        $message .= "https://admin.frenchora.co.za/";

        $headers = "From: $siteName <$fromEmail>\r\n";
        $headers .= "Reply-To: " . $contact_email . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Send email
        $mailSent = mail($adminEmail, $subject, $message, $headers);

        if (!$mailSent) {
            error_log("$siteName registration email failed for reference: " . $studentNumber);
        }

        // ============================================================
        // RETURN SUCCESS RESPONSE
        // ============================================================

        echo json_encode([
            "status" => "success", 
            "message" => "Registration details appended to admissions successfully!", 
            "student_number" => $studentNumber
        ]);
        exit();

    } catch (PDOException $ex) {
        echo json_encode(["status" => "error", "message" => "Database write failure: " . $ex->getMessage()]);
        exit();
    }
}

// If not POST request
echo json_encode(["status" => "error", "message" => "Invalid request method."]);
exit();
?>