<?php
session_start();
require_once 'chat_functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];
$current_user_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Create uploads directory if it doesn't exist
$upload_dir = "chat_uploads/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Get chat users
$chat_users = getChatUsers($current_user_id, $current_user_role, $conn);

// Handle selected chat
$selected_user_id = $_GET['user_id'] ?? ($chat_users[0]['id'] ?? null);
$selected_user = null;

if ($selected_user_id) {
    foreach ($chat_users as $user) {
        if ($user['id'] == $selected_user_id) {
            $selected_user = $user;
            break;
        }
    }
}

// Handle sending message and file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['message']) && $selected_user_id) {
        $message = trim($_POST['message']);
        $attachment_path = null;
        $attachment_type = null;
        
        // Handle file upload
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Validate file type and extension
            if (in_array($file['type'], $allowed_types) && 
                in_array($file_extension, $allowed_extensions) && 
                $file['size'] <= $max_size) {
                
                $file_name = uniqid() . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    $attachment_path = $file_path;
                    $attachment_type = strpos($file['type'], 'image/') === 0 ? 'image' : 'file';
                } else {
                    error_log("File upload failed: " . $file['error']);
                }
            } else {
                error_log("Invalid file type, extension, or size: " . $file['type'] . ", " . $file_extension . ", " . $file['size']);
            }
        }
        
        if (!empty($message) || $attachment_path) {
            // Use the function from chat_functions.php
            if ($attachment_path) {
                $message_id = sendMessageWithAttachment(
                    $current_user_id,
                    $current_user_role,
                    $selected_user_id,
                    $selected_user['role'],
                    $message,
                    $attachment_path,
                    $attachment_type,
                    $conn
                );
            } else {
                $message_id = sendMessage(
                    $current_user_id,
                    $current_user_role,
                    $selected_user_id,
                    $selected_user['role'],
                    $message,
                    $conn
                );
            }
            
            if ($message_id) {
                // Redirect to avoid form resubmission
                header("Location: chat.php?user_id=" . $selected_user_id);
                exit();
            } else {
                $error = "Failed to send message. Please try again.";
            }
        }
    }
}

// Get messages for selected chat
$messages = [];
$last_message_id = 0;
if ($selected_user_id) {
    $messages = getChatMessages($current_user_id, $selected_user_id, $conn);
    if (!empty($messages)) {
        $last_message_id = end($messages)['id'];
    }
}

$unread_count = getUnreadCount($current_user_id, $conn);

// Close connection for SSE (will reopen in SSE script)
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - BOIYETS FITNESS GYM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #0f0f0f;
            color: #e2e8f0;
            margin: 0;
            padding: 0;
            height: 100vh;
            overflow: hidden;
        }
        
        .chat-container {
            height: 100vh;
            display: flex;
            background: #0f0f0f;
        }
        
        /* Sidebar Styles */
        .users-sidebar {
            width: 380px;
            background: #111111;
            border-right: 1px solid #1f1f1f;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            z-index: 20;
        }
        
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #1f1f1f;
        }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }
        
        /* Chat Area Styles */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #0f0f0f;
            transition: transform 0.3s ease;
        }
        
        .chat-header {
            background: #111111;
            padding: 1.25rem;
            border-bottom: 1px solid #1f1f1f;
            flex-shrink: 0;
        }
        
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: #0f0f0f;
        }
        
        .message-input-container {
            padding: 1.25rem;
            border-top: 1px solid #1f1f1f;
            background: #111111;
            flex-shrink: 0;
        }
        
        /* User List Styles */
        .user-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #151515;
            margin-bottom: 0.5rem;
            border: 1px solid transparent;
        }
        
        .user-item:hover {
            background: #1a1a1a;
            border-color: #333;
        }
        
        .user-item.active {
            background: #1a1a1a;
            border-color: #fbbf24;
            box-shadow: 0 0 0 1px rgba(251, 191, 36, 0.1);
        }
        
        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            margin-right: 1rem;
            border: 2px solid rgba(251, 191, 36, 0.3);
            flex-shrink: 0;
        }
        
        .user-info {
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.95rem;
        }
        
        .user-role {
            font-size: 0.8rem;
            color: #94a3b8;
            text-transform: capitalize;
            margin-top: 0.125rem;
        }
        
        .user-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        
        .unread-badge {
            background: #ef4444;
            color: white;
            border-radius: 8px;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        
        /* Message Styles */
        .message {
            max-width: 70%;
            margin-bottom: 1rem;
            padding: 0;
            position: relative;
        }
        
        .message.sent {
            margin-left: auto;
        }
        
        .message.received {
            margin-right: auto;
        }
        
        .message-bubble {
            padding: 0.875rem 1rem;
            border-radius: 16px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            backdrop-filter: blur(10px);
            word-wrap: break-word;
        }
        
        .message.sent .message-bubble {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a1a;
            border: none;
            border-bottom-right-radius: 6px;
        }
        
        .message.received .message-bubble {
            background: #1a1a1a;
            color: white;
            border-bottom-left-radius: 6px;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.5rem;
            text-align: right;
        }
        
        .message.received .message-time {
            text-align: left;
        }
        
        /* Input Styles */
        .message-input {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            color: white;
            font-size: 0.95rem;
            resize: none;
            outline: none;
            transition: all 0.2s ease;
            min-height: 48px;
            max-height: 120px;
        }
        
        .message-input:focus {
            border-color: #fbbf24;
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
        }
        
        .send-button {
            background: #fbbf24;
            color: #1a1a1a;
            border: none;
            border-radius: 12px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-left: 0.75rem;
            flex-shrink: 0;
        }
        
        .send-button:hover {
            background: #f59e0b;
            transform: scale(1.02);
        }
        
        .send-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6b7280;
        }
        
        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            color: #374151;
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            padding: 1rem;
            color: #94a3b8;
            font-size: 0.9rem;
        }
        
        .typing-dots {
            display: flex;
            margin-left: 8px;
        }
        
        .typing-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #94a3b8;
            margin: 0 1px;
            animation: typing 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }
        
        /* Role Badges */
        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        
        .role-admin { background: rgba(239, 68, 68, 0.15); color: #fca5a5; }
        .role-trainer { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
        .role-client { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
        
        /* Attachment Styles */
        .attachment-preview {
            margin-top: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .attachment-image-container {
            position: relative;
            display: inline-block;
        }
        
        .attachment-image {
            max-width: 240px;
            max-height: 240px;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .attachment-image:hover {
            transform: scale(1.02);
        }
        
        .image-download-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 8px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            opacity: 0;
        }
        
        .attachment-image-container:hover .image-download-btn {
            opacity: 1;
        }
        
        .image-download-btn:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: scale(1.1);
        }
        
        .attachment-file {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .attachment-icon {
            color: #fbbf24;
            flex-shrink: 0;
        }
        
        .attachment-info {
            flex: 1;
            min-width: 0;
        }
        
        .attachment-name {
            font-weight: 500;
            color: white;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .attachment-size {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .attachment-download {
            color: #fbbf24;
            cursor: pointer;
            transition: color 0.2s ease;
            padding: 8px;
            border-radius: 8px;
            background: rgba(251, 191, 36, 0.1);
            flex-shrink: 0;
        }
        
        .attachment-download:hover {
            color: #f59e0b;
            background: rgba(251, 191, 36, 0.2);
        }
        
        /* File Upload */
        .file-upload-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .file-input {
            display: none;
        }
        
        .file-upload-button {
            background: rgba(251, 191, 36, 0.1);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.2);
            border-radius: 12px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        
        .file-upload-button:hover {
            background: rgba(251, 191, 36, 0.2);
            transform: scale(1.02);
        }
        
        /* Preview Modal */
        .preview-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 2rem;
        }
        
        .preview-image {
            max-width: 90%;
            max-height: 90%;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }
        
        .close-preview {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: rgba(239, 68, 68, 0.9);
            color: white;
            border: none;
            border-radius: 12px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            z-index: 1001;
        }
        
        .close-preview:hover {
            background: rgba(239, 68, 68, 1);
            transform: scale(1.05);
        }
        
        /* Message Layout */
        .message-with-attachment {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .message-text {
            word-wrap: break-word;
            line-height: 1.5;
        }
        
        .typing-indicator-container {
            min-height: 48px;
        }

        /* Mobile Navigation */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #111111;
            border-top: 1px solid #1f1f1f;
            z-index: 30;
            padding: 0.75rem;
        }
        
        .mobile-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            color: #9ca3af;
            transition: all 0.2s ease;
            min-height: 52px;
            text-decoration: none;
            border-radius: 12px;
        }
        
        .mobile-nav-item.active {
            color: #fbbf24;
            background: rgba(251, 191, 36, 0.1);
        }
        
        .mobile-nav-item i {
            width: 20px;
            height: 20px;
            margin-bottom: 0.25rem;
        }
        
        .mobile-nav-label {
            font-size: 0.75rem;
        }

        /* Mobile Toggle Buttons */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: #fbbf24;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        
        .mobile-toggle:hover {
            background: rgba(251, 191, 36, 0.1);
        }

        /* Mobile Overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 15;
        }

        /* Topbar */
        .topbar {
            background: #111111;
            border-bottom: 1px solid #1f1f1f;
            padding: 1rem 1.5rem;
            flex-shrink: 0;
        }

        /* Dashboard Button */
        .dashboard-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(251, 191, 36, 0.1);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.2);
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .dashboard-button:hover {
            background: rgba(251, 191, 36, 0.2);
            transform: translateY(-1px);
        }

        /* Scrollbars */
        .messages-container::-webkit-scrollbar {
            width: 6px;
        }

        .messages-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: rgba(251, 191, 36, 0.3);
            border-radius: 3px;
        }

        .messages-container::-webkit-scrollbar-thumb:hover {
            background: rgba(251, 191, 36, 0.5);
        }

        .sidebar-content::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: rgba(251, 191, 36, 0.2);
            border-radius: 2px;
        }

        /* Mobile Responsive - IMPROVED FOR TYPING */
        @media (max-width: 768px) {
            .chat-container {
                position: relative;
                overflow: hidden;
                height: 100vh;
                height: calc(var(--vh, 1vh) * 100);
            }
            
            .users-sidebar {
                position: absolute;
                top: 0;
                left: 0;
                bottom: 0;
                width: 100%;
                transform: translateX(-100%);
                z-index: 20;
            }
            
            .users-sidebar.active {
                transform: translateX(0);
            }
            
            .chat-main {
                width: 100%;
                transform: translateX(0);
            }
            
            .chat-main.hidden {
                transform: translateX(100%);
            }
            
            .mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .mobile-overlay.active {
                display: block;
            }
            
            .mobile-nav {
                display: flex;
            }
            
            .message {
                max-width: 85%;
            }
            
            .attachment-image {
                max-width: 200px;
                max-height: 200px;
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                margin-right: 0.875rem;
            }
            
            .user-item {
                padding: 0.875rem;
                margin-bottom: 0.375rem;
            }
            
            .sidebar-header,
            .chat-header {
                padding: 1rem;
            }
            
            .messages-container {
                padding: 1rem;
                padding-bottom: 80px; /* Space for mobile nav */
            }
            
            .message-input-container {
                padding: 1rem;
                padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
                position: sticky;
                bottom: 0;
                background: #111111;
                border-top: 1px solid #1f1f1f;
            }
            
            .topbar {
                padding: 0.875rem 1rem;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            
            .file-upload-button,
            .send-button {
                width: 44px;
                height: 44px;
            }
            
            /* Improved mobile input */
            .message-input {
                font-size: 16px; /* Prevents zoom on iOS */
                min-height: 44px; /* Better touch target */
                padding: 0.75rem 1rem;
            }
            
            /* Adjust message container for mobile keyboard */
            .messages-container {
                padding-bottom: 120px; /* Extra space for keyboard */
            }
            
            /* Hide dashboard button text on very small screens */
            .dashboard-button span {
                display: none;
            }
            
            .dashboard-button {
                padding: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .message {
                max-width: 90%;
            }
            
            .attachment-image {
                max-width: 160px;
                max-height: 160px;
            }
            
            .user-avatar {
                width: 36px;
                height: 36px;
                margin-right: 0.75rem;
            }
            
            .user-item {
                padding: 0.75rem;
            }
            
            .sidebar-header,
            .chat-header {
                padding: 0.875rem;
            }
            
            .messages-container {
                padding: 0.875rem;
                padding-bottom: 100px;
            }
            
            .message-input-container {
                padding: 0.875rem;
                padding-bottom: calc(0.875rem + env(safe-area-inset-bottom, 0px));
            }
            
            .message-input {
                padding: 0.75rem;
                font-size: 16px; /* Prevent zoom */
                min-height: 44px;
            }
            
            .file-upload-button,
            .send-button {
                width: 40px;
                height: 40px;
            }
            
            .dashboard-button {
                padding: 0.5rem;
            }
            
            .dashboard-button span {
                display: none;
            }
        }

        /* Safe area support */
        @supports(padding: max(0px)) {
            .mobile-nav {
                padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
            }
            
            .message-input-container {
                padding-bottom: max(1rem, env(safe-area-inset-bottom));
            }
        }

        /* Keyboard handling for mobile */
        .keyboard-open .messages-container {
            padding-bottom: 200px;
        }
        
        .keyboard-open .mobile-nav {
            display: none;
        }

        /* Focus states for better accessibility */
        .message-input:focus {
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
        }
        
        .send-button:focus,
        .file-upload-button:focus,
        .dashboard-button:focus {
            outline: 2px solid #fbbf24;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Topbar -->
    <header class="topbar flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <button class="mobile-toggle" id="backButton" onclick="goBack()">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </button>
            <button class="mobile-toggle" id="menuToggle" onclick="toggleUsersSidebar()">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <h1 class="text-lg font-bold text-yellow-400 truncate">BOIYETS FITNESS GYM</h1>
        </div>
        <div class="flex items-center space-x-3">
            <!-- Dashboard Button -->
            <a href="<?php 
                if ($current_user_role == 'admin') echo 'admin_dashboard.php';
                elseif ($current_user_role == 'trainer') echo 'trainer_dashboard.php';
                else echo 'client_dashboard.php';
            ?>" class="dashboard-button">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                <span>Dashboard</span>
            </a>
            <div class="flex items-center space-x-3">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($current_user_name, 0, 1)); ?>
                </div>
                <span class="text-sm font-medium hidden sm:inline truncate max-w-[120px]"><?php echo htmlspecialchars($current_user_name); ?></span>
            </div>
        </div>
    </header>

    <div class="chat-container">
        <!-- Users Sidebar -->
        <div class="users-sidebar" id="usersSidebar">
            <div class="sidebar-header">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <i data-lucide="message-circle" class="w-6 h-6 text-yellow-400"></i>
                        <div>
                            <h1 class="text-xl font-bold text-white">Messages</h1>
                            <?php if ($unread_count > 0): ?>
                                <p class="text-sm text-gray-400"><?php echo $unread_count; ?> unread message<?php echo $unread_count > 1 ? 's' : ''; ?></p>
                            <?php else: ?>
                                <p class="text-sm text-gray-400">No unread messages</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="mobile-toggle sm:hidden" onclick="toggleUsersSidebar()">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <!-- Dashboard Button in Sidebar -->
                <div class="mt-4">
                    <a href="<?php 
                        if ($current_user_role == 'admin') echo 'admin_dashboard.php';
                        elseif ($current_user_role == 'trainer') echo 'trainer_dashboard.php';
                        else echo 'client_dashboard.php';
                    ?>" class="dashboard-button inline-flex">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
            </div>
            
            <div class="sidebar-content">
                <?php if (empty($chat_users)): ?>
                    <div class="empty-state">
                        <i data-lucide="users" class="empty-state-icon"></i>
                        <p class="text-lg font-medium mb-2">No contacts available</p>
                        <p class="text-sm text-gray-500">You need to be assigned to chat with others</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($chat_users as $user): ?>
                        <a href="chat.php?user_id=<?php echo $user['id']; ?>" 
                           class="user-item <?php echo $selected_user_id == $user['id'] ? 'active' : ''; ?>"
                           onclick="if(window.innerWidth <= 768) toggleUsersSidebar();">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></div>
                                <div class="user-meta">
                                    <span class="user-role"><?php echo $user['role']; ?></span>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo $user['role']; ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($user['unread_count'] > 0): ?>
                                <div class="unread-badge">
                                    <?php echo $user['unread_count']; ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Chat Area -->
        <div class="chat-main" id="chatMain">
            <?php if ($selected_user): ?>
                <div class="chat-header">
                    <div class="flex items-center gap-4">
                        <button class="mobile-toggle lg:hidden" onclick="toggleUsersSidebar()">
                            <i data-lucide="menu" class="w-5 h-5"></i>
                        </button>
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($selected_user['full_name'] ?: $selected_user['username'], 0, 1)); ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-lg font-semibold text-white truncate">
                                <?php echo htmlspecialchars($selected_user['full_name'] ?: $selected_user['username']); ?>
                            </h2>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-sm text-gray-400 truncate"><?php echo $selected_user['role']; ?></span>
                                <span class="role-badge role-<?php echo $selected_user['role']; ?>">
                                    <?php echo $selected_user['role']; ?>
                                </span>
                                <div id="typingStatus" class="text-xs text-yellow-400 hidden flex-shrink-0">
                                    <i data-lucide="pencil" class="w-3 h-3"></i>
                                    typing...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="messages-container" id="messagesContainer">
                    <?php if (empty($messages)): ?>
                        <div class="empty-state">
                            <i data-lucide="message-square" class="empty-state-icon"></i>
                            <p class="text-lg font-medium mb-2">No messages yet</p>
                            <p class="text-sm text-gray-500">Start a conversation with <?php echo htmlspecialchars($selected_user['full_name'] ?: $selected_user['username']); ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="message <?php echo $message['sender_id'] == $current_user_id ? 'sent' : 'received'; ?>" data-message-id="<?php echo $message['id']; ?>">
                                <div class="message-bubble">
                                    <div class="message-with-attachment">
                                        <?php if (!empty($message['message'])): ?>
                                            <div class="message-text"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($message['attachment_path'])): ?>
                                            <div class="attachment-preview">
                                                <?php if ($message['attachment_type'] == 'image'): ?>
                                                    <div class="attachment-image-container">
                                                        <img src="<?php echo htmlspecialchars($message['attachment_path']); ?>" 
                                                             alt="Attachment" 
                                                             class="attachment-image"
                                                             onclick="openImagePreview('<?php echo htmlspecialchars($message['attachment_path']); ?>')">
                                                        <a href="<?php echo htmlspecialchars($message['attachment_path']); ?>" 
                                                           download 
                                                           class="image-download-btn"
                                                           title="Download Image">
                                                            <i data-lucide="download"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="attachment-file">
                                                        <i data-lucide="file" class="attachment-icon"></i>
                                                        <div class="attachment-info">
                                                            <div class="attachment-name"><?php echo basename($message['attachment_path']); ?></div>
                                                            <div class="attachment-size">File</div>
                                                        </div>
                                                        <a href="<?php echo htmlspecialchars($message['attachment_path']); ?>" 
                                                           download 
                                                           class="attachment-download"
                                                           title="Download File">
                                                            <i data-lucide="download"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('g:i A', strtotime($message['created_at'])); ?>
                                        <?php if ($message['is_read'] && $message['sender_id'] == $current_user_id): ?>
                                            <i data-lucide="check" class="w-3 h-3 inline ml-1"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="typing-indicator-container" id="typingIndicator">
                        <!-- Typing indicator will appear here -->
                    </div>
                </div>
                
                <form method="POST" class="message-input-container" enctype="multipart/form-data" id="messageForm">
                    <div class="flex items-end gap-3">
                        <div class="file-upload-container">
                            <input type="file" id="attachment" name="attachment" class="file-input" accept="image/*,.pdf">
                            <label for="attachment" class="file-upload-button">
                                <i data-lucide="paperclip"></i>
                            </label>
                        </div>
                        <textarea name="message" class="message-input" placeholder="Type your message..." 
                                  rows="1" oninput="autoResize(this)" id="messageInput"></textarea>
                        <button type="submit" class="send-button" id="sendButton">
                            <i data-lucide="send"></i>
                        </button>
                    </div>
                    <div id="attachmentPreview" class="attachment-preview" style="display: none;"></div>
                </form>
            <?php else: ?>
                <div class="flex-1 flex items-center justify-center">
                    <div class="empty-state">
                        <i data-lucide="message-circle" class="empty-state-icon"></i>
                        <h3 class="text-xl font-semibold mb-3 text-center">Select a conversation</h3>
                        <p class="text-gray-500 text-center mb-4">Choose someone to start chatting</p>
                        <button class="bg-yellow-500 hover:bg-yellow-600 text-black px-6 py-3 rounded-lg font-medium transition-colors lg:hidden" onclick="toggleUsersSidebar()">
                            Browse Contacts
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-nav" aria-label="Mobile navigation">
        <a href="<?php 
            if ($current_user_role == 'admin') echo 'admin_dashboard.php';
            elseif ($current_user_role == 'trainer') echo 'trainer_dashboard.php';
            else echo 'client_dashboard.php';
        ?>" class="mobile-nav-item">
            <i data-lucide="home"></i>
            <span class="mobile-nav-label">Dashboard</span>
        </a>
        <button class="mobile-nav-item active" onclick="toggleUsersSidebar()">
            <i data-lucide="message-circle"></i>
            <span class="mobile-nav-label">Chats</span>
        </button>
        <button class="mobile-nav-item" onclick="focusMessageInput()">
            <i data-lucide="edit"></i>
            <span class="mobile-nav-label">New</span>
        </button>
    </nav>

    <!-- Image Preview Modal -->
    <div id="imagePreview" class="preview-container" style="display: none;">
        <button class="close-preview" onclick="closeImagePreview()">
            <i data-lucide="x"></i>
        </button>
        <img id="previewImage" class="preview-image" src="" alt="Preview">
        <a id="downloadPreviewBtn" class="image-download-btn-large" style="position: absolute; top: 80px; right: 20px; background: rgba(0, 0, 0, 0.7); color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease;" title="Download Image">
            <i data-lucide="download"></i>
        </a>
    </div>

    <script>
        // Initialize icons
        lucide.createIcons();
        
        // Set viewport height for mobile
        function setViewportHeight() {
            let vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        
        setViewportHeight();
        window.addEventListener('resize', setViewportHeight);
        window.addEventListener('orientationchange', setViewportHeight);
        
        // Mobile navigation functions
        function toggleUsersSidebar() {
            const sidebar = document.getElementById('usersSidebar');
            const overlay = document.getElementById('mobileOverlay');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                
                // Close keyboard when opening sidebar
                if (sidebar.classList.contains('active')) {
                    document.activeElement?.blur();
                }
            }
        }
        
        function goBack() {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('usersSidebar');
                if (sidebar.classList.contains('active')) {
                    toggleUsersSidebar();
                } else {
                    window.history.back();
                }
            } else {
                window.history.back();
            }
        }
        
        function focusMessageInput() {
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.focus();
                // Scroll to bottom when focusing input
                setTimeout(scrollToBottom, 100);
            } else {
                toggleUsersSidebar();
            }
        }
        
        // Close sidebar when clicking overlay
        document.getElementById('mobileOverlay').addEventListener('click', toggleUsersSidebar);
        
        // Auto-resize textarea
        function autoResize(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }
        
        // Scroll to bottom of messages
        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        }
        
        // Mobile keyboard handling
        function handleMobileKeyboard() {
            if (window.innerWidth <= 768) {
                const messageInput = document.getElementById('messageInput');
                const messagesContainer = document.getElementById('messagesContainer');
                const mobileNav = document.querySelector('.mobile-nav');
                
                if (messageInput) {
                    messageInput.addEventListener('focus', function() {
                        document.body.classList.add('keyboard-open');
                        if (mobileNav) mobileNav.style.display = 'none';
                        // Scroll to bottom when keyboard opens
                        setTimeout(scrollToBottom, 300);
                    });
                    
                    messageInput.addEventListener('blur', function() {
                        document.body.classList.remove('keyboard-open');
                        if (mobileNav) mobileNav.style.display = 'flex';
                    });
                }
            }
        }
        
        // File upload preview
        const fileInput = document.getElementById('attachment');
        const previewContainer = document.getElementById('attachmentPreview');
        
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            previewContainer.innerHTML = '';
            previewContainer.style.display = 'none';
            
            if (file) {
                const maxSize = 5 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewContainer.innerHTML = `
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <img src="${e.target.result}" alt="Preview" class="w-12 h-12 rounded-lg object-cover">
                                    <div class="min-w-0">
                                        <div class="text-sm text-white truncate">${file.name}</div>
                                        <div class="text-xs text-gray-400">${(file.size / 1024).toFixed(1)} KB</div>
                                    </div>
                                </div>
                                <button type="button" onclick="removeAttachment()" class="text-red-400 hover:text-red-300 flex-shrink-0">
                                    <i data-lucide="x"></i>
                                </button>
                            </div>
                        `;
                        previewContainer.style.display = 'block';
                        lucide.createIcons();
                    };
                    reader.readAsDataURL(file);
                } else {
                    previewContainer.innerHTML = `
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i data-lucide="file" class="text-yellow-400 flex-shrink-0"></i>
                                <div class="min-w-0">
                                    <div class="text-sm text-white truncate">${file.name}</div>
                                    <div class="text-xs text-gray-400">${(file.size / 1024).toFixed(1)} KB</div>
                                </div>
                            </div>
                            <button type="button" onclick="removeAttachment()" class="text-red-400 hover:text-red-300 flex-shrink-0">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                    `;
                    previewContainer.style.display = 'block';
                    lucide.createIcons();
                }
            }
        });
        
        // Handle Enter key to send message (but allow Shift+Enter for new line)
        document.querySelector('textarea[name="message"]')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Image preview functions
        function openImagePreview(imageSrc) {
            document.getElementById('previewImage').src = imageSrc;
            document.getElementById('downloadPreviewBtn').href = imageSrc;
            document.getElementById('downloadPreviewBtn').download = imageSrc.split('/').pop();
            document.getElementById('imagePreview').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }
        
        function closeImagePreview() {
            document.getElementById('imagePreview').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // Remove attachment
        function removeAttachment() {
            document.getElementById('attachment').value = '';
            document.getElementById('attachmentPreview').style.display = 'none';
        }
        
        // Close preview when clicking outside image
        document.getElementById('imagePreview').addEventListener('click', function(e) {
            if (e.target === this) {
                closeImagePreview();
            }
        });
        
        // Server-Sent Events for real-time updates
        let eventSource;
        let lastMessageId = <?php echo $last_message_id; ?>;
        let currentUserId = <?php echo $current_user_id; ?>;
        let selectedUserId = <?php echo $selected_user_id ?: 'null'; ?>;
        let typingTimer;
        
        function setupSSE() {
            if (!selectedUserId) return;
            
            eventSource = new EventSource(`chat_sse.php?user_id=${currentUserId}&last_id=${lastMessageId}`);
            
            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                
                if (data.type === 'new_message' && data.message.sender_id == selectedUserId) {
                    addNewMessage(data.message);
                    updateUnreadCounts();
                } else if (data.type === 'typing' && data.sender_id == selectedUserId) {
                    showTypingIndicator(data.sender_name);
                } else if (data.type === 'stop_typing' && data.sender_id == selectedUserId) {
                    hideTypingIndicator();
                }
            };
            
            eventSource.onerror = function(event) {
                console.log('SSE error:', event);
                setTimeout(setupSSE, 3000);
            };
        }
        
        function addNewMessage(message) {
            const messagesContainer = document.getElementById('messagesContainer');
            const emptyState = messagesContainer.querySelector('.empty-state');
            
            if (emptyState) {
                emptyState.remove();
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${message.sender_id == currentUserId ? 'sent' : 'received'}`;
            messageDiv.setAttribute('data-message-id', message.id);
            
            let attachmentHtml = '';
            if (message.attachment_path) {
                if (message.attachment_type === 'image') {
                    attachmentHtml = `
                        <div class="attachment-preview">
                            <div class="attachment-image-container">
                                <img src="${message.attachment_path}" alt="Attachment" class="attachment-image" onclick="openImagePreview('${message.attachment_path}')">
                                <a href="${message.attachment_path}" download class="image-download-btn" title="Download Image">
                                    <i data-lucide="download"></i>
                                </a>
                            </div>
                        </div>
                    `;
                } else {
                    attachmentHtml = `
                        <div class="attachment-preview">
                            <div class="attachment-file">
                                <i data-lucide="file" class="attachment-icon"></i>
                                <div class="attachment-info">
                                    <div class="attachment-name">${basename(message.attachment_path)}</div>
                                    <div class="attachment-size">File</div>
                                </div>
                                <a href="${message.attachment_path}" download class="attachment-download" title="Download File">
                                    <i data-lucide="download"></i>
                                </a>
                            </div>
                        </div>
                    `;
                }
            }
            
            const readIcon = message.is_read && message.sender_id == currentUserId ? '<i data-lucide="check" class="w-3 h-3 inline ml-1"></i>' : '';
            
            messageDiv.innerHTML = `
                <div class="message-bubble">
                    <div class="message-with-attachment">
                        ${message.message ? `<div class="message-text">${escapeHtml(message.message)}</div>` : ''}
                        ${attachmentHtml}
                    </div>
                    <div class="message-time">
                        ${new Date(message.created_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}
                        ${readIcon}
                    </div>
                </div>
            `;
            
            messagesContainer.appendChild(messageDiv);
            scrollToBottom();
            lastMessageId = message.id;
            
            lucide.createIcons();
        }
        
        function showTypingIndicator(senderName) {
            const typingContainer = document.getElementById('typingIndicator');
            typingContainer.innerHTML = `
                <div class="typing-indicator">
                    ${senderName} is typing
                    <div class="typing-dots">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                </div>
            `;
            scrollToBottom();
        }
        
        function hideTypingIndicator() {
            const typingContainer = document.getElementById('typingIndicator');
            typingContainer.innerHTML = '';
        }
        
        function updateUnreadCounts() {
            const unreadCountElement = document.getElementById('unreadCount');
            if (unreadCountElement) {
                const currentCount = parseInt(unreadCountElement.textContent);
                if (currentCount > 1) {
                    unreadCountElement.textContent = currentCount - 1;
                } else {
                    unreadCountElement.remove();
                }
            }
        }
        
        // Typing indicators
        const messageInput = document.getElementById('messageInput');
        let isTyping = false;
        
        messageInput?.addEventListener('input', function() {
            if (!isTyping) {
                isTyping = true;
                sendTypingStatus(true);
            }
            
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                isTyping = false;
                sendTypingStatus(false);
            }, 1000);
        });
        
        function sendTypingStatus(typing) {
            if (!selectedUserId) return;
            
            fetch('chat_typing.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user_id=${currentUserId}&receiver_id=${selectedUserId}&typing=${typing ? 1 : 0}`
            });
        }
        
        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function basename(path) {
            return path.split('/').pop();
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            scrollToBottom();
            handleMobileKeyboard();
            
            if (selectedUserId) {
                setupSSE();
            }
            
            if (selectedUserId && messageInput) {
                setTimeout(() => {
                    messageInput.focus();
                }, 500);
            }
            
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    document.getElementById('usersSidebar').classList.remove('active');
                    document.getElementById('mobileOverlay').classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
            
            // Handle page visibility changes
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    sendTypingStatus(false);
                }
            });
        });
        
        // Clean up when leaving page
        window.addEventListener('beforeunload', function() {
            if (eventSource) {
                eventSource.close();
            }
            sendTypingStatus(false);
        });
        
        // Handle escape key to close modals/sidebars
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('imagePreview').style.display !== 'none') {
                    closeImagePreview();
                } else if (window.innerWidth <= 768 && document.getElementById('usersSidebar').classList.contains('active')) {
                    toggleUsersSidebar();
                }
            }
        });
        
        // Prevent form submission on Enter in textarea (already handled above)
        document.getElementById('messageForm')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.type !== 'textarea') {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>



