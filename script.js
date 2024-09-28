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

                        eventSource.addEventListener('end', function(event) {
                            outputDiv.innerHTML += "Compilation complete<br>";
                            eventSource.close(); // 接続を閉じる
                        }); // <-- ここで閉じ括弧を追加

                        eventSource.onerror = function(event) {
                            console.error('EventSource failed:', event);
                            eventSource.close(); // エラー発生時に接続を閉じる
                        };
                    } else {
                        outputDiv.innerHTML = 'Error: ' + data.message;
                    }
                } catch (error) {
                    console.error('Error:', error);
                    outputDiv.innerHTML = 'Error: ' + error;
                }
            });

            editButtons.appendChild(miz2prelButton);
        }
    }
});