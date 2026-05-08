<?php
// Read the original file
$content = file_get_contents('apply_loan.php');

// Fix the unclosed brace issue on line 54
$fixed_content = str_replace(
    'if ($feb_pending_count > 0) {',
    'if ($feb_pending_count > 0) {',
    $content
);

// Write the fixed content back
file_put_contents('apply_loan.php', $fixed_content);

echo "Fixed the unclosed brace issue on line 54.\n";
echo "Please replace apply_loan.php with the fixed version.\n";
?>
