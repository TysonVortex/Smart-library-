<?php
session_start();

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Include database connection
include 'db.php';

// Error logging function
function logError($message, $error = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Delete Book Error: $message";
    if ($error) {
        $logMessage .= " - Database Error: $error";
    }
    error_log($logMessage, 3, '../logs/delete_book_errors.log');
}

// Redirect function with error handling
function redirectWithMessage($success = false, $message = null) {
    $param = $success ? 'deleted=1' : 'error=1';
    if ($message) {
        $param .= '&message=' . urlencode($message);
    }
    
    header("Location: ../frontend/view_books.php?$param");
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    logError("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    redirectWithMessage(false, "Invalid request method");
}

// Check for book ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    logError("No book ID provided");
    redirectWithMessage(false, "No book ID provided");
}

// Validate and sanitize book ID
$book_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if ($book_id === false || $book_id <= 0) {
    logError("Invalid book ID: " . $_GET['id']);
    redirectWithMessage(false, "Invalid book ID");
}

// Optional: CSRF protection (if implementing CSRF tokens)
if (isset($_SESSION['csrf_token']) && isset($_GET['token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        logError("CSRF token mismatch for book ID: $book_id");
        redirectWithMessage(false, "Security token mismatch");
    }
}

// Database connection check
if (!$conn) {
    logError("Database connection failed");
    redirectWithMessage(false, "Database connection error");
}

try {
    // Start transaction for data integrity
    $conn->begin_transaction();
    
    // First, check if the book exists and is not already deleted
    $check_stmt = $conn->prepare("SELECT book_id, title, is_deleted FROM book WHERE book_id = ?");
    if (!$check_stmt) {
        throw new Exception("Failed to prepare check statement: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $book_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        $check_stmt->close();
        $conn->rollback();
        logError("Book not found: ID $book_id");
        redirectWithMessage(false, "Book not found");
    }
    
    $book_data = $result->fetch_assoc();
    $check_stmt->close();
    
    // Check if already deleted
    if ($book_data['is_deleted']) {
        $conn->rollback();
        logError("Attempted to delete already deleted book: ID $book_id");
        redirectWithMessage(false, "Book has already been deleted");
    }
    
    // Optional: Check if book is currently borrowed (if you have a borrowing system)
    $borrow_check_stmt = $conn->prepare("SELECT COUNT(*) as borrowed_count FROM borrowing WHERE book_id = ? AND return_date IS NULL");
    if ($borrow_check_stmt) {
        $borrow_check_stmt->bind_param("i", $book_id);
        $borrow_check_stmt->execute();
        $borrow_result = $borrow_check_stmt->get_result();
        $borrow_data = $borrow_result->fetch_assoc();
        $borrow_check_stmt->close();
        
        if ($borrow_data['borrowed_count'] > 0) {
            $conn->rollback();
            logError("Attempted to delete borrowed book: ID $book_id");
            redirectWithMessage(false, "Cannot delete book that is currently borrowed");
        }
    }
    
    // Update book to mark as deleted
    $delete_stmt = $conn->prepare("UPDATE book SET is_deleted = TRUE, updated_at = NOW() WHERE book_id = ?");
    if (!$delete_stmt) {
        throw new Exception("Failed to prepare delete statement: " . $conn->error);
    }
    
    $delete_stmt->bind_param("i", $book_id);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Failed to execute delete statement: " . $delete_stmt->error);
    }
    
    // Check if any rows were affected
    if ($delete_stmt->affected_rows === 0) {
        $delete_stmt->close();
        $conn->rollback();
        logError("No rows affected when deleting book: ID $book_id");
        redirectWithMessage(false, "Failed to delete book");
    }
    
    $delete_stmt->close();
    
    // Optional: Log the deletion for audit trail
    $audit_stmt = $conn->prepare("INSERT INTO audit_log (action, table_name, record_id, old_values, user_id, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    if ($audit_stmt) {
        $action = 'DELETE';
        $table_name = 'book';
        $old_values = json_encode($book_data);
        $user_id = $_SESSION['user_id'] ?? null; // If you have user sessions
        
        $audit_stmt->bind_param("ssisi", $action, $table_name, $book_id, $old_values, $user_id);
        $audit_stmt->execute();
        $audit_stmt->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Log successful deletion
    $success_message = "Book successfully deleted: ID $book_id, Title: " . $book_data['title'];
    error_log("[$timestamp] " . $success_message, 3, '../logs/delete_book_success.log');
    
    // Redirect with success message
    redirectWithMessage(true, "Book '{$book_data['title']}' has been successfully deleted");
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log the error
    logError("Exception occurred while deleting book ID $book_id", $e->getMessage());
    
    // Redirect with error message
    redirectWithMessage(false, "An error occurred while deleting the book");
    
} finally {
    // Close database connection
    if ($conn) {
        $conn->close();
    }
}

// This should never be reached, but just in case
redirectWithMessage(false, "Unexpected error occurred");
?>