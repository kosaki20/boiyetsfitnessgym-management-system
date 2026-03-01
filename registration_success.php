<?php
session_start();
if (!isset($_SESSION['registration_success'])) {
    header("Location: signup.php");
    exit();
}

$email = $_SESSION['verification_email'] ?? '';
unset($_SESSION['registration_success']);
unset($_SESSION['verification_email']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - Boiyets Fitness Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #ffffff;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        
        .success-card {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .success-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #059669);
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: rgba(16, 185, 129, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border: 3px solid #10b981;
        }
        
        .success-icon i {
            color: #10b981;
            font-size: 2rem;
        }
        
        h1 {
            color: #10b981;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        p {
            color: #94a3b8;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .email-highlight {
            color: #fbbf24;
            font-weight: 600;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #10b981;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin: 5px;
        }
        
        .btn:hover {
            background: #059669;
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #10b981;
            color: #10b981;
        }
        
        .btn-outline:hover {
            background: #10b981;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-envelope"></i>
            </div>
            
            <h1>Verify Your Email</h1>
            
            <p>
                We've sent a verification link to:<br>
                <span class="email-highlight"><?php echo htmlspecialchars($email); ?></span>
            </p>
            
            <p>
                Please check your email and click the verification link to activate your account. 
                The link will expire in 24 hours.
            </p>
            
            <p style="font-size: 0.9rem; color: #64748b;">
                Didn't receive the email? Check your spam folder or 
                <a href="resend_verification.php" style="color: #fbbf24; text-decoration: none;">
                    click here to resend
                </a>
            </p>
            
            <div style="margin-top: 30px;">
                <a href="index.php" class="btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Go to Login
                </a>
                <a href="signup.php" class="btn btn-outline">
                    <i class="fas fa-user-plus"></i>
                    Register Another
                </a>
            </div>
        </div>
    </div>
</body>
</html>



