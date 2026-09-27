<?php
function debug_to_console($data) {
    $output = $data;
    if (is_array($output)) {
        $output = implode(',', $output);
    }
    
    // Escape any special characters that could break the JavaScript
    $output = addslashes($output);
    
    echo "<script>console.log('Debug: " . $output . "');</script>";
}
?>