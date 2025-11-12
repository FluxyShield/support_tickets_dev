<?php
define('ROOT_PATH', __DIR__);
require_once 'config.php';

echo "<h1>Test d'envoi d'email</h1>";

$result = sendEmail(
    'votre-email@test.com',  // Remplacez par votre email
    'Test depuis PHP',
    '<h1>Ceci est un test</h1><p>Si vous recevez ce message, PHPMailer fonctionne !</p>'
);

if ($result) {
    echo "<p style='color:green;'>✅ Email envoyé avec succès ! Vérifiez votre boîte.</p>";
} else {
    echo "<p style='color:red;'>❌ Échec de l'envoi. Consultez les logs PHP.</p>";
}

// Afficher les variables d'environnement (masquez les mots de passe)
echo "<h2>Configuration actuelle :</h2>";
echo "<pre>";
echo "MAIL_HOST: " . ($_ENV['MAIL_HOST'] ?? 'NON DÉFINI') . "\n";
echo "MAIL_USERNAME: " . ($_ENV['MAIL_USERNAME'] ?? 'NON DÉFINI') . "\n";
echo "MAIL_PASSWORD: " . (empty($_ENV['MAIL_PASSWORD']) ? 'NON DÉFINI' : '***défini***') . "\n";
echo "MAIL_FROM_EMAIL: " . ($_ENV['MAIL_FROM_EMAIL'] ?? 'NON DÉFINI') . "\n";
echo "</pre>";
?>