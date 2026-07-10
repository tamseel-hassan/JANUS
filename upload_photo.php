<?php
// upload_photo.php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    $user_id = $_POST['user_id'];
    $upload_dir = 'uploads/users/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_file = $upload_dir . $user_id . '.jpg';
    
    // Check if file was uploaded
    if (isset($_FILES['user_photo']) && $_FILES['user_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['user_photo']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            // Resize image to square (optional)
            $image = null;
            switch($file_type) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($_FILES['user_photo']['tmp_name']);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($_FILES['user_photo']['tmp_name']);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($_FILES['user_photo']['tmp_name']);
                    break;
            }
            
            if ($image) {
                // Get dimensions
                $width = imagesx($image);
                $height = imagesy($image);
                $size = min($width, $height);
                
                // Create square crop
                $square = imagecreatetruecolor(200, 200);
                imagecopyresampled($square, $image, 0, 0, ($width - $size) / 2, ($height - $size) / 2, 200, 200, $size, $size);
                
                // Save as JPEG
                imagejpeg($square, $target_file, 90);
                
                // Clean up
                imagedestroy($image);
                imagedestroy($square);
                
                $_SESSION['message'] = "Photo uploaded successfully!";
            } else {
                // Fallback to simple move
                move_uploaded_file($_FILES['user_photo']['tmp_name'], $target_file);
                $_SESSION['message'] = "Photo uploaded successfully!";
            }
        } else {
            $_SESSION['error'] = "Only JPG, PNG, and GIF files are allowed.";
        }
    } else {
        $_SESSION['error'] = "No file uploaded or upload error occurred.";
    }
}

// Redirect back
$referer = $_SERVER['HTTP_REFERER'] ?? 'top_performers.php';
header("Location: $referer");
exit;
?>
