<?php
session_start();
// Simple test form without all the complexity
?>
<form method="POST" action="member_registration.php">
    <input type="hidden" name="member_type" value="walk-in">
    <input type="text" name="full_name" value="Test User" required>
    <input type="number" name="age" value="25" required>
    <input type="tel" name="contact_number" value="09123456789" required>
    <input type="text" name="address" value="Test Address" required>
    <select name="membership_plan" required>
        <option value="daily">Daily - ₱40</option>
    </select>
    <button type="submit">Test Register</button>
</form>



