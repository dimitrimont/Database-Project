<?php
// This file contains the database access information.
// This file establishes a connection to MySQL and selects the database.

// Set the database access information as constants:
const DBCONNSTRING = 'mysql:host=localhost;dbname=CSC355FA25Football';
const DB_USER = 'djm9825';
const DB_PASSWORD = 'MCSdm8806';

// Make the connection:
try {
    $dbc = new PDO(DBCONNSTRING, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('DB Connection Failed: ' . $e->getMessage());
}

?>
