<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_mizarproofchecker extends ActionPlugin
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
                $this->clearTempFiles();  // 追加: 一時ファイル削除の呼び出し
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
        }
    }

    // source用のコンパイルリクエスト処理
    private function handleSourceCompileRequest()
    {
        global $INPUT;
        $pageContent = $INPUT->post->str('content');
        $mizarData = $this->extractMizarContent($pageContent);

        if ($mizarData === null) {
            $this->sendAjaxResponse(false, 'Mizar content not found');
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
        $this->streamCompileOutput($filePath);

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

        $fileName = trim($matches[0][1]);
        $combinedContent = '';

        foreach ($matches as $match) {
            if (trim($match[1]) !== $fileName) {
                return ['error' => 'File name mismatch in <mizar> tags'];
            }
            $combinedContent .= trim($match[2]) . "\n";
        }

        return ['fileName' => $fileName, 'content' => $combinedContent];
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

    //  Clear all temporary files in the TEXT folder
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

    // コンパイル出力のストリーム
    private function streamCompileOutput($filePath)
    {
        $workPath = $this->getConf('mizar_work_dir');
        $sharePath = rtrim($this->getConf('mizar_share_dir'), '/\\') . '/';

        chdir($workPath);

        $tempErrFilename = str_replace('.miz', '.err', $filePath);
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

            // エラー処理
            if (file_exists($tempErrFilename)) {
                $errors = [];
                $errorLines = file($tempErrFilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($errorLines as $errorLine) {
                    if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $errorLine, $matches)) {
                        $errors[] = [
                            'code' => intval($matches[3]),
                            'line' => intval($matches[1]),
                            'column' => intval($matches[2]),
                            'message' => $this->getMizarErrorMessages($sharePath . '/mizar.msg')[intval($matches[3])] ?? 'Unknown error'
                        ];
                    }
                }
                if (!empty($errors)) {
                    echo "event: compileErrors\n";
                    echo "data: " . json_encode($errors) . "\n\n";
                    ob_flush();
                    flush();
                    return;
                }
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
}
