<?php
session_start();

include 'db.php';

$id = $_GET['id'];

// Delete assignment from the database
$sql = "DELETE FROM exam WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo "Assignment deleted successfully";
} else {
    echo "Error deleting assignment: " . $conn->error;
}

$stmt->close();
$conn->close();

// Redirect back to the assignments page
header("Location: view.php");
exit;
?>