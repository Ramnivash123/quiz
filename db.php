<?php
// db.php

// Database credentials
$host = 'localhost';       // or your database host
$dbname = 'image_database'; // name of your database
$username = 'root';         // your database username
$password = '';             // your database password (if any)

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
