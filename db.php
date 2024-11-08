<?php
// db.php

// Database credentials
$host = 'sql113.infinityfree.com';       // or your database host
$dbname = 'if0_37559006_quiz'; // name of your database
$username = 'if0_37559006';         // your database username
$password = 'E8Yb5B2Hx3Z';             // your database password (if any)

try {
    // Create a new PDO instance
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    // Set PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Connection successful message (you can comment this out later)
    // echo "Connected successfully";
} catch (PDOException $e) {
    // In case of error, output message and stop execution
    die("Connection failed: " . $e->getMessage());
}
?>
