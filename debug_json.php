<?php
$content = file_get_contents('data/host_messages.json');
$result = json_decode($content);
if ($result === null) {
    $error = json_last_error_msg();
    echo "JSON Error: $error\n";
} else {
    echo 'JSON is valid!';
}
?>
