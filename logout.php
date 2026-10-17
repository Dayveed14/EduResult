<?php
require __DIR__ . '/includes/bootstrap.php';

logout();
session_start();
flash('success', 'You have signed out.');
redirect('login.php');
