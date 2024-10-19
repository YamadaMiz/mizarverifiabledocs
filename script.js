"use strict";
document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelector('.editButtons');
    if (editButtons && !document.getElementById('edbtn__miz2prel')) {
        // URLに「&do=edit」が含まれているかチェックすることで全体編集を判定
        const isFullEdit = document.location.search.includes('&do=edit');

        // 全体編集の場合にのみmiz2prelボタンを表示
        if (isFullEdit) {
            const miz2prelButton = document.createElement('button');
            miz2prelButton.textContent = 'miz2prel';
            miz2prelButton.id = 'edbtn__miz2prel';
            miz2prelButton.type = 'button';
            miz2prelButton.classList.add('miz2prel-button');

            const clearButton = document.createElement('button'); // Clearボタンの作成
            clearButton.textContent = 'Clear';
            clearButton.id = 'edbtn__clear';
            clearButton.type = 'button';
            clearButton.classList.add('clear-button');

            // Clearボタンのクリックイベント
            clearButton.addEventListener('click', async function() {
                const outputDiv = document.getElementById('compileResult');
                if (outputDiv) {
                    outputDiv.innerHTML = ''; // 出力内容をクリア
                    outputDiv.style.backgroundColor = ''; // 背景色をリセット
                }

                // サーバーに一時ファイルを削除するリクエストを送信
                try {
                    const response = await fetch(DOKU_BASE + "lib/exe/ajax.php?call=clear_temp_files", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded"
                        }
                    });

                    const data = await response.json();
                    if (data.success) {
                        console.log(data.message);
                    } else {
                        console.error("Failed to clear temporary files:", data.message);
                    }
                } catch (error) {
                    console.error("Error clearing temporary files:", error);
                }

                // Clearボタンを消してmiz2prelボタンを再表示
                clearButton.style.display = 'none'; // Clearボタンを非表示
                miz2prelButton.style.display = 'inline-block'; // miz2prelボタンを表示
            });

            miz2prelButton.addEventListener('click', async function() {
                const editor = document.getElementById('wiki__text');
                if (!editor) {
                    alert('Editor not found');
                    return;
                }

                const pageContent = editor.value;
                const editBar = document.getElementById('wiki__editbar');
                let outputDiv = document.getElementById('compileResult');
                if (!outputDiv) {
                    outputDiv = document.createElement('div');
                    outputDiv.id = 'compileResult';
                }

                if (editBar) {
                    editBar.parentNode.insertBefore(outputDiv, editBar.nextSibling);
                }

                try {
                    const response = await fetch(DOKU_BASE + 'lib/exe/ajax.php?call=source_compile', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'content=' + encodeURIComponent(pageContent)
                    });

                    const data = await response.json();

                    if (data.success) {
                        // SSEで結果を受信
                        const eventSource = new EventSource(DOKU_BASE + 'lib/exe/ajax.php?call=source_sse');

                        eventSource.onmessage = function(event) {
                            outputDiv.innerHTML += event.data + '<br>';
                        };

                        // エラー情報がある場合に受信するイベントリスナー
                        eventSource.addEventListener('compileErrors', function(event) {
                            try {
                                const errors = JSON.parse(event.data);
                                let errorContent = ''; // エラー内容を表示するための変数
                                errors.forEach(function(error) {
                                    const { line, column, message } = error;
                                    // リンクを削除してエラーメッセージのみを表示
                                    errorContent += `❌ ${message} (Ln: ${line}, Col: ${column})<br>`;
                                });
                                outputDiv.innerHTML += errorContent; // エラー情報を表示

                                // エラーメッセージが含まれている場合は背景色を赤にする
                                outputDiv.style.backgroundColor = '#fcc';
                            } catch (e) {
                                console.error('Failed to parse error data:', e);
                            }
                        });

                        eventSource.addEventListener('end', function(event) {
                            outputDiv.innerHTML += "Compilation complete<br>";
                            if (!outputDiv.innerHTML.includes('❌')) {
                                outputDiv.style.backgroundColor = '#ccffcc';
                            }
                            eventSource.close(); // 接続を閉じる
                        });

                        eventSource.onerror = function(event) {
                            console.error('EventSource failed:', event);
                            eventSource.close(); // エラー発生時に接続を閉じる
                        };

                        // miz2prelボタンを消してclearボタンを表示
                        miz2prelButton.style.display = 'none'; // miz2prelボタンを非表示に
                        clearButton.style.display = 'inline-block'; // clearボタンを表示
                    } else {
                        outputDiv.innerHTML = 'Error: ' + data.message;
                        outputDiv.style.backgroundColor = '#fcc'; // エラーがある場合は背景色を赤にする
                    }
                } catch (error) {
                    console.error('Error:', error);
                    outputDiv.innerHTML = 'Error: ' + error;
                    outputDiv.style.backgroundColor = '#fcc'; // エラーがある場合は背景色を赤にする
                }
            });

            editButtons.appendChild(miz2prelButton);
            editButtons.appendChild(clearButton); // Clearボタンを追加
        }
    }
});