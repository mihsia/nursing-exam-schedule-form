<?php
return [
    // Synology MariaDB 10 commonly uses port 3307. Check MariaDB package settings if yours differs.
    'db_host' => '127.0.0.1',
    'db_port' => 3307,
    // If TCP is disabled, set the socket path instead, for example:
    // 'db_socket' => '/run/mysqld/mysqld10.sock',
    'db_socket' => '',
    'db_name' => 'nursing_exam',
    'db_user' => 'nursing_exam_user',
    'db_pass' => 'CHANGE_ME',
    // Required when the frontend uses Firebase Authentication to call api/records.php.
    'firebase_project_id' => 'nursing-exam-schedule',
    'session_name' => 'NURSING_EXAM_SESSION',
];
