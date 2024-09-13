<?php
class action_plugin_mizarproofchecker extends \dokuwiki\Extension\ActionPlugin {
    /** @inheritDoc */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call');
    }

    function handle_ajax_call(Doku_Event $event, $param) {
        unset($param); // 未使用のパラメータを無効化
        if ($event->data === 'source_sse') {
            $event->preventDefault();
            $event->stopPropagation();
            $this->handleSourceSSERequest();
            return;
        } elseif ($event->data === 'source_compile') {
            $event->preventDefault();
            $event->stopPropagation();
            $this->handleSourceCompileRequest();
            return;
        } elseif ($event->data === 'view_compile') {
            $event->preventDefault();
            $event->stopPropagation();
            $this->handleViewCompileRequest();
            return;
        } elseif ($event->data === 'view_sse') {
            $event->preventDefault();
            $event->stopPropagation();
            $this->handleViewSSERequest();
            return;
        }
    }

    // source用のコンパイルリクエスト処理
    private function handleSourceCompileRequest() {
        global $INPUT;
        $pageContent = $INPUT->post->str('content');

        $mizarData = $this->extractMizarContent($pageContent);

        if ($mizarData === null) {
            $this->sendAjaxResponse(false, 'Mizar content not found');
            return;
        }

        $filePath = $this->saveMizarContent($mizarData);

        // セッションにファイルパスを保存
        session_start();
        $_SESSION['source_filepath'] = $filePath;

        // コンパイルが成功したことをクライアントに通知
        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    // source用のSSEリクエスト処理
    private function handleSourceSSERequest() {
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

        // miz2prelコマンドを実行し、結果をストリーミング
        $this->streamSourceOutput($filePath);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        ob_flush();
        flush();
    }

    private function extractMizarContent($pageContent) {
        $pattern = '/<mizar\s+([^>]+)>(.*?)<\/mizar>/s';
        preg_match_all($pattern, $pageContent, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return null; // <mizar>タグが見つからない場合
        }

        $fileName = trim($matches[0][1]); // 最初の<mizar>タグからファイル名を取得
        $combinedContent = '';

        foreach ($matches as $match) {
            // 各<mizar>タグのファイル名が一致することを確認
            if (trim($match[1]) !== $fileName) {
                return ['error' => 'File name mismatch in <mizar> tags'];
            }

            // 各<mizar>タグの内容を連結
            $combinedContent .= trim($match[2]) . "\n";
        }

        return ['fileName' => $fileName, 'content' => $combinedContent];
    }

    private function saveMizarContent($mizarData) {
        // ワークパスを取得し、末尾にスラッシュやバックスラッシュがあれば削除
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\');
        $filePath = $workPath . "/TEXT/" . $mizarData['fileName'];
        file_put_contents($filePath, $mizarData['content']);
        return $filePath;
    }

    private function streamSourceOutput($filePath) {
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

    // view用のコンパイルリクエスト処理
    private function handleViewCompileRequest() {
        global $INPUT;
        $content = $INPUT->post->str('content');

        // 一時ファイルの作成
        $filePath = $this->createTempFile($content);

        // セッションにファイルパスを保存
        session_start();
        $_SESSION['view_filepath'] = $filePath;

        // コンパイル準備完了をレスポンス
        $this->sendAjaxResponse(true, 'Mizar content processed successfully');
    }

    // view用のSSEリクエスト処理
    private function handleViewSSERequest() {
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

        // コンパイル結果をストリーミング
        $this->streamCompileOutput($filePath);

        echo "event: end\n";
        echo "data: Compilation complete\n\n";
        ob_flush();
        flush();
    }

    // 一時ファイル作成はviewでのみ使用
    private function createTempFile($content) {
        $workPath = rtrim($this->getConf('mizar_work_dir'), '/\\') . '/TEXT/';
        $uniqueName = str_replace('.', '_', uniqid('mizar', true));
        $tempFilename = $workPath . $uniqueName . ".miz";
        file_put_contents($tempFilename, $content);
        return $tempFilename;
    }

    private function streamCompileOutput($filePath) {
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

                if (file_exists($tempErrFilename)) {
                    $errors = [];
                    $errorLines = file($tempErrFilename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($errorLines as $errorLine) {
                        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)/', $errorLine, $matches)) {
                            $errors[] = [
                                'code' => intval($matches[3]),
                                'line' => intval($matches[1]),
                                'column' => intval($matches[2]),
                                'message' => $this->getMizarErrorMessages($workPath . '/MIZAR/mizar.msg')[intval($matches[3])] ?? 'Unknown error'
                            ];
                        }
                    }
                    echo "event: compileErrors\n";
                    echo "data: " . json_encode($errors) . "\n\n";
                    ob_flush();
                    flush();
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

    // Mizarエラーメッセージを取得するメソッド
    private function getMizarErrorMessages($mizarMsgFile) {
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

    private function sendAjaxResponse($success, $message, $data = '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
        exit;
    }
}
