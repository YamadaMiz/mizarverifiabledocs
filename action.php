<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * DokuWiki Plugin Mizar Verifiable Docs (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Yamada, M. <yamadam@mizar.work>
 */

class action_plugin_mizarverifiabledocs extends ActionPlugin
{
    /**
     * Registers a callback function for a given event
     *
     * @param EventHandler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call');
    }

    /**
     * Handles AJAX requests
     *
     * @param Event $event
     * @param $param
     */
    public function handle_ajax_call(Event $event, $param)
    {
        unset($param); // 未使用のパラメータを無効化

        switch ($event->data) {
            case 'clear_temp_files':
                $event->preventDefault();
                $event->stopPropagation();
                $this->clearTempFiles();
                break;
            case 'source_sse':
                $event->preventDefault();
                $event->stopPropagation();
                $this->handleSourceSSERequest();
                break;
            case 'source_compile':
                $event->preventDefault();
                $event->stopPropagation();
                $this->handleSourceCompileRequest();
                break;
            case 'view_compile':
                $event->preventDefault();
                $event->stopPropagation();
                $this->handleViewCompileRequest();
                break;
            case 'view_sse':
                $event->preventDefault();
                $event->stopPropagation();
                $this->handleViewSSERequest();
                break;
            case 'create_combined_file':
                $event->preventDefault();
                $event->stopPropagation();
                $this->handle_create_combined_file();
                break;
        }
    }

    // source用のコンパイルリクエスト処理
    private function handleSourceCompileRequest()
    {
        global $INPUT;
        $pageContent = $INPUT->post->str('content');
        $mizarData = $this->extractMizarContent($pageContent);

        // エラーチェックを追加
        if ($mizarData === null) {
            $this->sendAjaxResponse(false, 'Mizar content not found');
            return;
        } elseif (isset($mizarData['error'])) {
            $this->sendAjaxResponse(false, $mizarData['error']);
            return;
        }

        $filePath = $this->saveMizarContent($mizarData);

        session_start();
        $_SESSION['source_filepath'] = $filePath;

        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    // source用のSSEリクエスト処理
    private function handleSourceSSERequest()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');

        session_start();
        if (!isset($_SESSION['source_filepath'])) {
            echo "data: Mizar file path not found in session\n\n";
            ob_flush();
            flush();
            return;
        }

        $filePath = $_SESSION['source_filepath'];
        $this->streamSourceOutput($filePath);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        ob_flush();
        flush();
    }

    // view用のコンパイルリクエスト処理
    private function handleViewCompileRequest()
    {
        global $INPUT;
        $content = $INPUT->post->str('content');

        $filePath = $this->createTempFile($content);

        session_start();
        $_SESSION['view_filepath'] = $filePath;

        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    // view用のSSEリクエスト処理
    private function handleViewSSERequest()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');

        session_start();
        if (!isset($_SESSION['view_filepath'])) {
            echo "data: Mizar file path not found in session\n\n";
            ob_flush();
            flush();
            return;
        }

        $filePath = $_SESSION['view_filepath'];
        $this->streamViewCompileOutput($filePath);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        ob_flush();
        flush();
    }

    // Mizarコンテンツの抽出
    private function extractMizarContent($pageContent)
    {
        $pattern = '/<mizar\s+([^>]+)>(.*?)<\/mizar>/s';
        preg_match_all($pattern, $pageContent, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return null;
        }

        // 最初のファイル名を取得し、拡張子を除去
        $fileName = trim($matches[0][1]);
        $fileNameWithoutExt = preg_replace('/\.miz$/i', '', $fileName);

        // ファイル名のバリデーションを追加
        if (!$this->isValidFileName($fileNameWithoutExt)) {
            return ['error' => "Invalid characters in file name: '{$fileNameWithoutExt}'. Only letters, numbers, underscores (_), and apostrophes (') are allowed, up to 8 characters."];
        }

        $combinedContent = '';

        foreach ($matches as $match) {
            $currentFileName = trim($match[1]);
            $currentFileNameWithoutExt = preg_replace('/\.miz$/i', '', $currentFileName);

            if ($currentFileNameWithoutExt !== $fileNameWithoutExt) {
                return ['error' => "File name mismatch in <mizar> tags: '{$fileNameWithoutExt}' and '{$currentFileNameWithoutExt}'"];
            }

            // バリデーションを各ファイル名にも適用
            if (!$this->isValidFileName($currentFileNameWithoutExt)) {
                return ['error' => "Invalid characters in file name: '{$currentFileNameWithoutExt}'. Only letters, numbers, underscores (_), and apostrophes (') are allowed, up to 8 characters."];
            }

            $combinedContent .= trim($match[2]) . "\n";
        }

        // ファイル名に拡張子を付加
        $fullFileName = $fileNameWithoutExt . '.miz';

        return ['fileName' => $fullFileName, 'content' => $combinedContent];
    }

    // ファイル名のバリデーション関数を追加
    private function isValidFileName($fileName)
    {
        // ファイル名の長さをチェック（最大8文字）
        if (strlen($fileName) > 8) {
            return false;
        }

        // 許可される文字のみを含むかチェック
        if (!preg_match('/^[A-Za-z0-9_\']+$/', $fileName)) {
            return false;
        }

        return true;
    }

    // Mizarコンテンツの保存
    private function saveMizarContent($mizarData)
    {
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\');
        $filePath = $workPath . "/TEXT/" . $mizarData['fileName'];
        file_put_contents($filePath, $mizarData['content']);
        return $filePath;
    }

    // source用の出力をストリーム
    private function streamSourceOutput($filePath)
    {
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\');
        chdir($workPath);

        $command = "miz2prel " . escapeshellarg($filePath);
        $process = proc_open($command, array(1 => array("pipe", "w")), $pipes);

        if (is_resource($process)) {
            while ($line = fgets($pipes[1])) {
                echo "data: " . $line . "\n\n";
                ob_flush();
                flush();
            }
            fclose($pipes[1]);

            // エラー処理の追加
            $errFilename = str_replace('.miz', '.err', $filePath);
            if ($this->handleCompilationErrors($errFilename, rtrim($this->getConf('mizar_share_dir'), '/\\') . '/mizar.msg')) {
                // エラーがあった場合は処理を終了
                proc_close($process);
                return;
            }

            proc_close($process);
        }
    }

    // view用の一時ファイル作成
    private function createTempFile($content)
    {
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\') . '/TEXT/';
        $uniqueName = str_replace('.', '_', uniqid('tmp', true));
        $tempFilename = $workPath . $uniqueName . ".miz";
        file_put_contents($tempFilename, $content);
        return $tempFilename;
    }

    // 一時ファイルの削除
    private function clearTempFiles()
    {
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\') . '/TEXT/';
        $files = glob($workPath . '*');  // TEXTフォルダ内のすべてのファイルを取得

        $errors = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                // ファイルが使用中かどうか確認
                if (!$this->is_file_locked($file)) {
                    $retries = 3; // 最大3回リトライ
                    while ($retries > 0) {
                        if (unlink($file)) {
                            break; // 削除成功
                        }
                        $errors[] = "Error deleting $file: " . error_get_last()['message'];
                        $retries--;
                        sleep(1); // 1秒待ってリトライ
                    }
                    if ($retries === 0) {
                        $errors[] = "Failed to delete: $file";  // 削除失敗
                    }
                } else {
                    $errors[] = "File is locked: $file";  // ファイルがロックされている
                }
            }
        }

        if (empty($errors)) {
            $this->sendAjaxResponse(true, 'Temporary files cleared successfully');
        } else {
            $this->sendAjaxResponse(false, 'Some files could not be deleted', $errors);
        }
    }

    // ファイルがロックされているかをチェックする関数
    private function is_file_locked($file)
    {
        $fileHandle = @fopen($file, "r+");

        if ($fileHandle === false) {
            return true; // ファイルが開けない、つまりロックされているかアクセス権がない
        }

        $locked = !flock($fileHandle, LOCK_EX | LOCK_NB); // ロックの取得を試みる（非ブロッキングモード）

        fclose($fileHandle);
        return $locked; // ロックが取得できなければファイルはロックされている
    }

    // View用コンパイル出力のストリーム
    private function streamViewCompileOutput($filePath)
    {
        $workPath = $this->getConf('mizar_work_dir');
        $sharePath = rtrim($this->getConf('mizar_share_dir'), '/\\') . '/';

        chdir($workPath);

        $errFilename = str_replace('.miz', '.err', $filePath);
        $command = "makeenv " . escapeshellarg($filePath);
        $process = proc_open($command, array(1 => array("pipe", "w"), 2 => array("pipe", "w")), $pipes);

        if (is_resource($process)) {
            while ($line = fgets($pipes[1])) {
                echo "data: " . mb_convert_encoding($line, 'UTF-8', 'SJIS') . "\n\n";
                ob_flush();
                flush();
            }
            fclose($pipes[1]);

            while ($line = fgets($pipes[2])) {
                echo "data: ERROR: " . mb_convert_encoding($line, 'UTF-8', 'SJIS') . "\n\n";
                ob_flush();
                flush();
            }
            fclose($pipes[2]);
            proc_close($process);

            // makeenvのエラー処理
            if ($this->handleCompilationErrors($errFilename, $sharePath . '/mizar.msg')) {
                return;
            }

            // verifierの実行
            $exePath = rtrim($this->getConf('mizar_exe_dir'), '/\\') . '/';
            $verifierPath = escapeshellarg($exePath . "verifier");
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $verifierPath .= ".exe";
            }
            $verifierCommand = $verifierPath . " -q -l " . escapeshellarg("TEXT/" . basename($filePath));

            $verifierProcess = proc_open($verifierCommand, array(1 => array("pipe", "w"), 2 => array("pipe", "w")), $verifierPipes);

            if (is_resource($verifierProcess)) {
                while ($line = fgets($verifierPipes[1])) {
                    echo "data: " . mb_convert_encoding($line, 'UTF-8', 'SJIS') . "\n\n";
                    ob_flush();
                    flush();
                }
                fclose($verifierPipes[1]);

                while ($line = fgets($verifierPipes[2])) {
                    echo "data: ERROR: " . mb_convert_encoding($line, 'UTF-8', 'SJIS') . "\n\n";
                    ob_flush();
                    flush();
                }
                fclose($verifierPipes[2]);
                proc_close($verifierProcess);

                // verifierのエラー処理
                if ($this->handleCompilationErrors($errFilename, $sharePath . '/mizar.msg')) {
                    return;
                }
            } else {
                echo "data: ERROR: Failed to execute verifier command.\n\n";
                ob_flush();
                flush();
            }
        } else {
            echo "data: ERROR: Failed to execute makeenv command.\n\n";
            ob_flush();
            flush();
        }
    }

    private function getMizarErrorMessages($mizarMsgFile)
    {
        $errorMessages = [];
        $lines = file($mizarMsgFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $isReadingErrorMsg = false;
        $key = 0;

        foreach ($lines as $line) {
            if (preg_match('/# (\d+)/', $line, $matches)) {
                $isReadingErrorMsg = true;
                $key = intval($matches[1]);
            } elseif ($isReadingErrorMsg) {
                $errorMessages[$key] = $line;
                $isReadingErrorMsg = false;
            }
        }

        return $errorMessages;
    }

    private function sendAjaxResponse($success, $message, $data = '')
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
        exit;
    }

    private function handleCompilationErrors($errFilename, $mizarMsgFilePath)
    {
        if (file_exists($errFilename)) {
            $errors = [];
            $errorLines = file($errFilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($errorLines as $errorLine) {
                if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $errorLine, $matches)) {
                    $errorCode = intval($matches[3]);
                    $errors[] = [
                        'code' => $errorCode,
                        'line' => intval($matches[1]),
                        'column' => intval($matches[2]),
                        'message' => $this->getMizarErrorMessages($mizarMsgFilePath)[$errorCode] ?? 'Unknown error'
                    ];
                }
            }
            if (!empty($errors)) {
                echo "event: compileErrors\n";
                echo "data: " . json_encode($errors) . "\n\n";
                ob_flush();
                flush();
                return true;
            }
        }
        return false;
    }

    private function handle_create_combined_file()
    {
        global $INPUT;

        // 投稿されたコンテンツを取得
        $combinedContent = $INPUT->post->str('content');
        $filename = $INPUT->post->str('filename', 'combined_file.miz'); // デフォルトのファイル名を指定

        // ファイルを保存せず、コンテンツを直接返す
        if (!empty($combinedContent)) {
            // ファイルの内容をレスポンスで返す（PHP側でファイルを作成しない）
            $this->sendAjaxResponse(true, 'File created successfully', [
                'filename' => $filename,
                'content' => $combinedContent
            ]);
            error_log("File content sent: " . $filename);
        } else {
            $this->sendAjaxResponse(false, 'Content is empty, no file created');
        }
    }
}
