
<?php

require_once __DIR__ . '/vendor/autoload.php';

$mailConfig = require __DIR__ . '/config/mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $mailConfig['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $mailConfig['username'];
    $mail->Password   = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $mailConfig['port'];

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name']
    );

    // Replace with your own email for testing.
    $mail->addAddress('bnbsshi@gmail.com');

    $mail->isHTML(true);
    $mail->Subject = 'HRIS Email Test';

    $mail->Body = '
        <h2>HRIS Email Test Successful!</h2>
        <p>Your Gmail SMTP configuration is working.</p>
    ';

    $mail->AltBody = 'HRIS Email Test Successful!';

    $mail->send();

    echo 'SUCCESS: Test email sent successfully!';

} catch (Exception $e) {
    error_log('HRIS email test failed: ' . $mail->ErrorInfo);
    echo 'ERROR: Email could not be sent. Check your SMTP configuration and server logs.';
}
