<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-blue-900 to-blue-700 min-h-screen flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-8 w-full max-w-md">
        <div class="text-center mb-8">
            <i class="fas fa-train text-4xl text-blue-600 mb-4"></i>
            <h1 class="text-2xl font-bold text-gray-800"><?php echo APP_NAME; ?></h1>
            <p class="text-gray-600 mt-2">Train Timetable Management System</p>
        </div>
        
        <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="login">
            
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">
                    <i class="fas fa-user mr-2"></i>Username
                </label>
                <input type="text" id="username" name="username" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                       placeholder="Enter your username">
            </div>
            
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">
                    <i class="fas fa-lock mr-2"></i>Password
                </label>
                <input type="password" id="password" name="password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                       placeholder="Enter your password">
            </div>
            
            <button type="submit" 
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
                <i class="fas fa-sign-in-alt mr-2"></i>Login
            </button>
        </form>
        
        <div class="mt-6 text-center text-sm text-gray-600">
            <p>Demo Accounts:</p>
            <div class="mt-2 space-y-1">
                <p><strong>Admin:</strong> admin / password</p>
                <p><strong>Scheduler:</strong> scheduler1 / password</p>
                <p><strong>Operator:</strong> operator1 / password</p>
            </div>
        </div>
        
        <div class="mt-8 text-center text-xs text-gray-500">
            <p>&copy; 2024 Ethio-Djibouti Railway Corporation</p>
            <p>Version <?php echo APP_VERSION; ?></p>
        </div>
    </div>
</body>
</html>
