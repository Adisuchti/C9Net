<?php
session_start();
require 'includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: views/home.php');
    exit;
} else {
    header('Location: views/home.php');
    exit;
}
?>

<head>
  <meta property="og:title" content="Cinder9 Intranet" />
  <meta property="og:description" content="Welcome to the Cinder9 Intranet" />
  <meta property="og:image" content="https://cinder9.com/favicon.png" />
  <meta property="og:url" content="https://cinder9.com" />
  <meta property="og:type" content="website" />
  <meta name="theme-color" content="#5865F2" /> <!-- Discord embed border color -->
</head>