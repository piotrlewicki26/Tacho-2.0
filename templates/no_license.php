<?php
/**
 * TachoPro 2.0 – Access denied (expired trial)
 * Redirects to billing page so the user can upgrade.
 * This template is now only reached if requireModule() was called directly
 * without going through the updated function – treated as a safety net.
 */
if (function_exists('flashSet')) {
    flashSet('warning', 'Twój okres próbny wygasł. Przejdź na plan Pro, aby uzyskać dostęp.');
}
header('Location: /billing.php');
exit;
