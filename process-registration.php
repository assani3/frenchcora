<?php
header('Content-Type: application/json');

// Live Production Connection Configuration
$host = "localhost";
$dbname = "frenchor_users";
$username = "frenchor_admin";
$password = "xVeQ5kjnQY3tbpMjRa6N";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database connection fault."]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract data matching the HTML input names precisely
    $learner_name       = trim($_POST['reg-learner-name'] ?? '');
    $learner_dob        = trim($_POST['reg-dob'] ?? '');
    $learner_age        = intval($_POST['reg-age'] ?? 0);
    $learner_gender     = trim($_POST['reg-gender'] ?? null); // New
    $parent_name        = trim($_POST['reg-parent-name'] ?? null);
    $contact_email      = trim($_POST['reg-email'] ?? '');
    $contact_phone      = trim($_POST['reg-phone'] ?? '');
    $contact_country    = trim($_POST['reg-country'] ?? null); // New
    $french_level       = trim($_POST['reg-french-level'] ?? '');
    $preferred_schedule = trim($_POST['reg-schedule'] ?? null); // New
    $placement_score    = intval($_POST['placement-score'] ?? 0);

    // Validation Check
    if (empty($learner_name) || empty($contact_email) || empty($french_level)) {
        echo json_encode(["status" => "error", "message" => "Required registration metrics are missing."]);
        exit();
    }

    // Generate Unique Application Reference Number (ST-YYYY-Random)
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
		$adminEmail = "admin@frenchora.co.za";

$subject = "New Frenchora Registration - " . $studentNumber;

$message = "A new learner registration has been submitted.\n\n";
$message .= "Reference Number: " . $studentNumber . "\n";
$message .= "Learner Name: " . $learner_name . "\n";
$message .= "Parent/Guardian: " . $parent_name . "\n";
$message .= "Email: " . $contact_email . "\n";
$message .= "Phone: " . $contact_phone . "\n";
$message .= "Country: " . $contact_country . "\n";
$message .= "French Level: " . $french_level . "\n";
$message .= "Preferred Schedule: " . $preferred_schedule . "\n\n";
$message .= "Please log in to the Frenchora Admin Portal to review the registration. https://admin.frenchora.co.za/ ";

$headers = "From: Frenchora Website info@frenchora.co.za\r\n";

if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
$headers .= "Reply-To: " . $contact_email . "\r\n";
}

$mailSent = mail($adminEmail, $subject, $message, $headers);

if (!$mailSent) {
error_log("Frenchora registration email failed for reference: " . $studentNumber);
}

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