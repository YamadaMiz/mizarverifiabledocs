<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * DokuWiki Plugin Mizar Verifiable Docs (Action Component)
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Yamada
 */
class action_plugin_mizarverifiabledocs extends ActionPlugin
{
    /* ===================== Register ===================== */

    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handle_tpl_content_display');
    }

    public function handle_tpl_content_display(Event $event, $_param)
    {
        if (!is_string($event->data)) return;

        $html = $event->data;
        if (strpos($html, 'mizarWrapper') !== false && strpos($html, 'id="hideAllButton"') === false) {
            $buttonHtml = '<div class="hideAllContainer">'
                        . '<button id="hideAllButton" class="hide-all-button">Hide All</button>'
                        . '<button id="showAllButton" class="hide-all-button" style="display:none;">Show All</button>'
                        . '<button id="resetAllButton" class="reset-all-button">Reset All</button>'
                        . '</div>';
            $event->data = $buttonHtml . $html;
        }
    }

    public function handle_ajax_call(Event $event, $param)
    {
        unset($param);
        switch ($event->data) {
            case 'clear_temp_files':
                $event->preventDefault(); $event->stopPropagation();
                $this->clearTempFiles(); break;

            case 'source_compile':
                $event->preventDefault(); $event->stopPropagation();
                $this->handleSourceCompileRequest(); break;

            case 'source_sse':
                $event->preventDefault(); $event->stopPropagation();
                $this->handleSourceSSERequest(); break;

            case 'view_compile':
                $event->preventDefault(); $event->stopPropagation();
                $this->handleViewCompileRequest(); break;

            case 'view_sse':
                $event->preventDefault(); $event->stopPropagation();
                $this->handleViewSSERequest(); break;

            case 'create_combined_file':
                $event->preventDefault(); $event->stopPropagation();
                $this->handle_create_combined_file(); break;

            case 'view_graph':
                $event->preventDefault(); $event->stopPropagation();
                $this->handleViewGraphRequest(); break;
        }
    }

    /* ===================== Helpers ===================== */

    private function isWindows(): bool {
        return strncasecmp(PHP_OS, 'WIN', 3) === 0;
    }

    /** 設定→未設定なら htdocs(= DOKU_INC の1つ上) 相対にフォールバックして正規化 */
    private function resolvePaths(): array
    {
        // DokuWiki ルート（末尾スラッシュなしに正規化）
        $dokuroot = rtrim(realpath(DOKU_INC), '/\\');

        // htdocs を「dokuwiki の 1つ上」から求める
        $htdocs = realpath($dokuroot . DIRECTORY_SEPARATOR . '..');
        if ($htdocs === false) $htdocs = dirname($dokuroot);

        $defM = $htdocs . DIRECTORY_SEPARATOR . 'MIZAR';
        $defW = $htdocs . DIRECTORY_SEPARATOR . 'work';

        // 設定値取得（空ならフォールバック）。相対指定が来たら htdocs 基準に解決
        $exe   = trim((string)$this->getConf('mizar_exe_dir'));
        $share = trim((string)$this->getConf('mizar_share_dir'));
        $work  = trim((string)$this->getConf('mizar_work_dir'));

        $isAbs = function(string $p): bool {
            // Windowsドライブ/UNC/Unix ざっくり対応
            return $p !== '' && (preg_match('~^[A-Za-z]:[\\/]|^\\\\\\\\|^/~', $p) === 1);
        };

        if ($exe   !== '' && !$isAbs($exe))   $exe   = $htdocs . DIRECTORY_SEPARATOR . $exe;
        if ($share !== '' && !$isAbs($share)) $share = $htdocs . DIRECTORY_SEPARATOR . $share;
        if ($work  !== '' && !$isAbs($work))  $work  = $htdocs . DIRECTORY_SEPARATOR . $work;

        $exe   = rtrim($exe   ?: $defM, '/\\');
        $share = rtrim($share ?: $defM, '/\\');
        $work  = rtrim($work  ?: $defW, '/\\');

        return ['exe' => $exe, 'share' => $share, 'work' => $work];
    }

    /** exeDir 直下 or exeDir\bin から実行ファイルを探す（.exe/.bat/.cmd 対応） */
    private function findExe(string $exeDir, string $name): ?string
    {
        if ($this->isWindows()) {
            $candidates = [
                "$exeDir\\$name.exe",          "$exeDir\\bin\\$name.exe",
                "$exeDir\\$name.bat",          "$exeDir\\bin\\$name.bat",
                "$exeDir\\$name.cmd",          "$exeDir\\bin\\$name.cmd",
                "$exeDir\\windows\\bin\\$name.exe",
                "$exeDir\\win\\bin\\$name.exe",
            ];
        } else {
            $candidates = [
                "$exeDir/$name",               "$exeDir/bin/$name",
            ];
        }
        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    /** 出力をUTF-8へ（WinはSJIS-WIN想定） */
    private function outUTF8(string $s): string
    {
        return $this->isWindows() ? mb_convert_encoding($s, 'UTF-8', 'SJIS-WIN') : $s;
    }

    /**
     * .exe は配列＋bypass_shell、.bat/.cmd は「cmd /C ""...""」の文字列＋shell経由で起動
     * @return array [$proc, $pipes] 失敗時は [null, []]
     */
    private function openProcess(string $exeOrBat, array $args, string $cwd): array
    {
        $des = [1 => ['pipe','w'], 2 => ['pipe','w']];

        if ($this->isWindows() && preg_match('/\.(bat|cmd)$/i', $exeOrBat)) {
            // cmd /C ""C:\path\tool.bat" "arg1" "arg2""
            $cmd = 'cmd.exe /C "'
                 . '"' . $exeOrBat . '"';
            foreach ($args as $a) {
                $cmd .= ' ' . escapeshellarg($a);
            }
            $cmd .= '"';
            $proc = proc_open($cmd, $des, $pipes, $cwd); // shell経由（bypass_shell=false）
        } else {
            $cmd = array_merge([$exeOrBat], $args);
            $proc = proc_open($cmd, $des, $pipes, $cwd, null, ['bypass_shell' => true]);
        }

        if (!is_resource($proc)) return [null, []];
        return [$proc, $pipes];
    }

    /* ===================== Source ===================== */

    private function handleSourceCompileRequest()
    {
        global $INPUT;
        $pageContent = $INPUT->post->str('content');
        $mizarData = $this->extractMizarContent($pageContent);

        if ($mizarData === null) { $this->sendAjaxResponse(false, 'Mizar content not found'); return; }
        if (isset($mizarData['error'])) { $this->sendAjaxResponse(false, $mizarData['error']); return; }

        $filePath = $this->saveMizarContent($mizarData);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['source_filepath'] = $filePath;

        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    private function handleSourceSSERequest()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['source_filepath'])) {
            echo "data: Mizar file path not found in session\n\n"; @ob_flush(); @flush(); return;
        }

        $this->streamSourceOutput($_SESSION['source_filepath']);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        @ob_flush(); @flush();
    }

    /* ===================== View ===================== */

    private function handleViewCompileRequest()
    {
        global $INPUT;
        $content = $INPUT->post->str('content');
        $filePath = $this->createTempFile($content);

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['view_filepath'] = $filePath;

        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    private function handleViewSSERequest()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['view_filepath'])) {
            echo "data: Mizar file path not found in session\n\n"; @ob_flush(); @flush(); return;
        }

        $this->streamViewCompileOutput($_SESSION['view_filepath']);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        @ob_flush(); @flush();
    }

    /***** view_graph: SVG を返す *****/
    private function handleViewGraphRequest()
    {
        global $INPUT;
        $content = $INPUT->post->str('content', '');
        if ($content === '') { $this->sendAjaxResponse(false, 'Empty content'); return; }

        $tmp = tempnam(sys_get_temp_dir(), 'miz');
        $miz = $tmp . '.miz';
        rename($tmp, $miz);
        file_put_contents($miz, $content);

        $parser = __DIR__ . '/script/miz2svg.py';
        $py     = $this->getConf('py_cmd') ?: 'python';
        $svg    = shell_exec(escapeshellcmd($py) . ' ' . escapeshellarg($parser) . ' ' . escapeshellarg($miz));
        @unlink($miz);

        if ($svg) $this->sendAjaxResponse(true, 'success', ['svg' => $svg]);
        else      $this->sendAjaxResponse(false, 'conversion failed');
    }

    /* ===================== Content utils ===================== */

    private function extractMizarContent($pageContent)
    {
        $pattern = '/<mizar\s+([^>]+)>(.*?)<\/mizar>/s';
        preg_match_all($pattern, $pageContent, $m, PREG_SET_ORDER);
        if (empty($m)) return null;

        $fn   = trim($m[0][1]);
        $stem = preg_replace('/\.miz$/i', '', $fn);
        if (!$this->isValidFileName($stem)) {
            return ['error' => "Invalid characters in file name: '{$stem}'. Only letters, numbers, underscores (_), and apostrophes (') are allowed, up to 8 characters."];
        }

        $combined = '';
        foreach ($m as $mm) {
            $cur = preg_replace('/\.miz$/i', '', trim($mm[1]));
            if ($cur !== $stem) return ['error' => "File name mismatch in <mizar> tags: '{$stem}' and '{$cur}'"];
            if (!$this->isValidFileName($cur)) return ['error' => "Invalid characters in file name: '{$cur}'."];
            $combined .= trim($mm[2]) . "\n";
        }
        return ['fileName' => $stem . '.miz', 'content' => $combined];
    }

    private function isValidFileName($fileName)
    {
        if (strlen($fileName) > 8) return false;
        return (bool)preg_match('/^[A-Za-z0-9_\']+$/', $fileName);
    }

    private function saveMizarContent($mizarData)
    {
        $paths = $this->resolvePaths();
        $textDir = $paths['work'] . DIRECTORY_SEPARATOR . 'TEXT';
        if (!is_dir($textDir)) @mkdir($textDir, 0777, true);

        $filePath = $textDir . DIRECTORY_SEPARATOR . $mizarData['fileName'];
        file_put_contents($filePath, $mizarData['content']);
        return $filePath;
    }

    private function createTempFile($content)
    {
        $paths = $this->resolvePaths();
        $textDir = $paths['work'] . DIRECTORY_SEPARATOR . 'TEXT';
        if (!is_dir($textDir)) @mkdir($textDir, 0777, true);

        $tempFilename = $textDir . DIRECTORY_SEPARATOR . str_replace('.', '_', uniqid('tmp', true)) . ".miz";
        file_put_contents($tempFilename, $content);
        return $tempFilename;
    }

    private function clearTempFiles()
    {
        $paths = $this->resolvePaths();
        $dir = $paths['work'] . DIRECTORY_SEPARATOR . 'TEXT' . DIRECTORY_SEPARATOR;
        $files = glob($dir . '*');

        $errors = [];
        foreach ($files as $f) {
            if (is_file($f)) {
                if (!$this->is_file_locked($f)) {
                    $ok = false; $retries = 5;
                    while ($retries-- > 0) { if (@unlink($f)) { $ok = true; break; } sleep(2); }
                    if (!$ok) $errors[] = "Failed to delete: $f";
                } else {
                    $errors[] = "File is locked: $f";
                }
            }
        }
        if ($errors) $this->sendAjaxResponse(false, 'Some files could not be deleted', $errors);
        else         $this->sendAjaxResponse(true, 'Temporary files cleared successfully');
    }

    private function is_file_locked($file)
    {
        $fp = @fopen($file, "r+");
        if ($fp === false) return true;
        $locked = !flock($fp, LOCK_EX | LOCK_NB);
        fclose($fp);
        return $locked;
    }

    /* ===================== Run (miz2prel/makeenv/verifier) ===================== */

    private function streamSourceOutput($filePath)
    {
        $paths = $this->resolvePaths();
        $workPath  = $paths['work'];
        $sharePath = $paths['share'];
        putenv("MIZFILES={$sharePath}");

        $exe = $this->findExe($paths['exe'], 'miz2prel');
        if ($exe === null) {
            echo "data: ERROR: miz2prel not found under {$paths['exe']} (or bin)\n\n"; @ob_flush(); @flush(); return;
        }

        [$proc, $pipes] = $this->openProcess($exe, [$filePath], $workPath);
        if (!$proc) { echo "data: ERROR: Failed to execute miz2prel.\n\n"; @ob_flush(); @flush(); return; }

        while (($line = fgets($pipes[1])) !== false) { echo "data: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[1]);
        while (($line = fgets($pipes[2])) !== false) { echo "data: ERROR: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[2]);
        proc_close($proc);

        $errFilename = str_replace('.miz', '.err', $filePath);
        $this->handleCompilationErrors($errFilename, $sharePath . DIRECTORY_SEPARATOR . 'mizar.msg');
    }

    private function streamViewCompileOutput($filePath)
    {
        $paths = $this->resolvePaths();
        $workPath  = $paths['work'];
        $sharePath = $paths['share'];
        putenv("MIZFILES={$sharePath}");

        // makeenv
        $makeenv = $this->findExe($paths['exe'], 'makeenv');
        if ($makeenv === null) { echo "data: ERROR: makeenv not found under {$paths['exe']} (or bin)\n\n"; @ob_flush(); @flush(); return; }
        [$proc, $pipes] = $this->openProcess($makeenv, [$filePath], $workPath);
        if (!$proc) { echo "data: ERROR: Failed to execute makeenv.\n\n"; @ob_flush(); @flush(); return; }
        while (($line = fgets($pipes[1])) !== false) { echo "data: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[1]);
        while (($line = fgets($pipes[2])) !== false) { echo "data: ERROR: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[2]);
        proc_close($proc);

        $errFilename = str_replace('.miz', '.err', $filePath);
        if ($this->handleCompilationErrors($errFilename, $sharePath . DIRECTORY_SEPARATOR . 'mizar.msg')) return;

        // verifier
        $verifier = $this->findExe($paths['exe'], 'verifier');
        if ($verifier === null) { echo "data: ERROR: verifier not found under {$paths['exe']} (or bin)\n\n"; @ob_flush(); @flush(); return; }
        $rel = 'TEXT' . DIRECTORY_SEPARATOR . basename($filePath);
        [$proc, $pipes] = $this->openProcess($verifier, ['-q','-l',$rel], $workPath);
        if (!$proc) { echo "data: ERROR: Failed to execute verifier.\n\n"; @ob_flush(); @flush(); return; }
        while (($line = fgets($pipes[1])) !== false) { echo "data: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[1]);
        while (($line = fgets($pipes[2])) !== false) { echo "data: ERROR: " . $this->outUTF8($line) . "\n\n"; @ob_flush(); @flush(); }
        fclose($pipes[2]);
        proc_close($proc);

        $this->handleCompilationErrors($errFilename, $sharePath . DIRECTORY_SEPARATOR . 'mizar.msg');
    }

    /* ===================== Errors ===================== */

    private function getMizarErrorMessages($mizarMsgFile)
    {
        if (!is_file($mizarMsgFile)) return [];
        $errorMessages = [];
        $lines = file($mizarMsgFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $isReading = false; $key = 0;
        foreach ($lines as $line) {
            if (preg_match('/# (\d+)/', $line, $m)) { $isReading = true; $key = (int)$m[1]; }
            elseif ($isReading) { $errorMessages[$key] = $line; $isReading = false; }
        }
        return $errorMessages;
    }

    private function handleCompilationErrors($errFilename, $mizarMsgFilePath)
    {
        if (!file_exists($errFilename)) return false;
        $errs = []; $lines = file($errFilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $ln) {
            if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $ln, $m)) {
                $code = (int)$m[3];
                $errs[] = [
                    'code'    => $code,
                    'line'    => (int)$m[1],
                    'column'  => (int)$m[2],
                    'message' => $this->getMizarErrorMessages($mizarMsgFilePath)[$code] ?? 'Unknown error'
                ];
            }
        }
        if ($errs) {
            echo "event: compileErrors\n";
            echo "data: " . json_encode($errs) . "\n\n";
            @ob_flush(); @flush();
            return true;
        }
        return false;
    }

    private function sendAjaxResponse($success, $message, $data = '')
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
        exit;
    }

    private function handle_create_combined_file()
    {
        global $INPUT;
        $combinedContent = $INPUT->post->str('content');
        $filename = $INPUT->post->str('filename', 'combined_file.miz');

        if (!empty($combinedContent)) {
            $this->sendAjaxResponse(true, 'File created successfully', [
                'filename' => $filename,
                'content'  => $combinedContent
            ]);
        } else {
            $this->sendAjaxResponse(false, 'Content is empty, no file created');
        }
    }
}
