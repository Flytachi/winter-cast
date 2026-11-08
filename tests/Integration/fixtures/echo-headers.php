<?php

// Router for `php -S`: answers with the method and the headers it received, as JSON.
header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
]);
