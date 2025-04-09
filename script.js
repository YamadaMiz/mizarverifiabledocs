/**
 * DokuWiki Plugin Mizar Verifiable Docs (Source View Script)
 *
 * @author Yamada, M. <yamadam@mizar.work>
 */
"use strict";
document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelector('.editButtons');
    if (editButtons && !document.getElementById('edbtn__miz2prel')) {
        const isFullEdit = document.location.search.includes('&do=edit');

        if (isFullEdit) {
            const miz2prelButton = document.createElement('button');
            miz2prelButton.textContent = 'miz2prel';
            miz2prelButton.id = 'edbtn__miz2prel';
            miz2prelButton.type = 'button';
            miz2prelButton.classList.add('miz2prel-button');

            const clearButton = document.createElement('button');
            clearButton.textContent = 'Clear';
            clearButton.id = 'edbtn__clear';
            clearButton.type = 'button';
            clearButton.classList.add('clear-button');

            clearButton.addEventListener('click', async function() {
                const outputDiv = document.getElementById('compileResult');
                if (outputDiv) {
                    outputDiv.innerHTML = '';
                    outputDiv.style.backgroundColor = '';
                }

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

                clearButton.style.display = 'none';
                miz2prelButton.style.display = 'inline-block';
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

                // ▼ Spinnerを追加
                const spinner = document.createElement('div');
                spinner.className = 'loading-spinner';
                spinner.innerHTML = 'Loading...';
                miz2prelButton.parentNode.insertBefore(spinner, miz2prelButton.nextSibling);

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
                        const eventSource = new EventSource(DOKU_BASE + 'lib/exe/ajax.php?call=source_sse');

                        eventSource.onmessage = function(event) {
                            outputDiv.innerHTML += event.data + '<br>';
                        };

                        eventSource.addEventListener('compileErrors', function(event) {
                            try {
                                const errors = JSON.parse(event.data);
                                let errorContent = '';
                                errors.forEach(function(error) {
                                    const { line, column, message } = error;
                                    errorContent += `❌ ${message} (Ln: ${line}, Col: ${column})<br>`;
                                });
                                outputDiv.innerHTML += errorContent;
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
                            eventSource.close();

                            // ▼ Spinnerを削除（コンパイル完了後）
                            spinner.remove();
                        });

                        eventSource.onerror = function(event) {
                            console.error('EventSource failed:', event);
                            eventSource.close();

                            // ▼ Spinnerを削除（エラー発生時）
                            spinner.remove();
                        };

                        miz2prelButton.style.display = 'none';
                        clearButton.style.display = 'inline-block';
                    } else {
                        outputDiv.innerHTML = 'Error: ' + data.message;
                        outputDiv.style.backgroundColor = '#fcc';

                        // ▼ Spinnerを削除（サーバーレスポンスエラー時）
                        spinner.remove();
                    }
                } catch (error) {
                    console.error('Error:', error);
                    outputDiv.innerHTML = 'Error: ' + error;
                    outputDiv.style.backgroundColor = '#fcc';

                    // ▼ Spinnerを削除（キャッチ時）
                    spinner.remove();
                }
            });

            editButtons.appendChild(miz2prelButton);
            editButtons.appendChild(clearButton);
        }
    }
});