"use strict;"
// 必要なモジュールをインポート
import { EditorState, Compartment, StateEffect, StateField, RangeSetBuilder } from "@codemirror/state";
import { EditorView, lineNumbers, showPanel, Decoration, ViewPlugin } from "@codemirror/view";
import { LRLanguage, LanguageSupport, syntaxHighlighting, HighlightStyle, syntaxTree } from "@codemirror/language";
import { parser } from "./mizar-parser.js";
import { tags as t } from "@lezer/highlight";
import { highlighting } from "./highlight.js"; // highlight.js からインポート

// スタイルの定義
const highlightStyle = HighlightStyle.define([
    { tag: t.controlKeyword, class: "control-keyword" },        // 制御キーワード
    { tag: t.function(t.keyword), class: "function-keyword" },    // サポート関数
    { tag: t.keyword, class: "general-keyword" },                // 一般的なキーワード
    { tag: t.typeName, class: "type-name" },                    // 型名やエンティティ名
    { tag: t.meta, class: "meta-info" },                        // メタ情報（推論句）
    { tag: t.lineComment, class: "line-comment" },              // 行コメント
    { tag: t.paren, class: "paren" },                            // 括弧
    { tag: t.brace, class: "brace" },                            // 中括弧
    { tag: t.squareBracket, class: "square-bracket" },          // 角括弧
]);

// パーサーの設定
let parserWithMetadata = parser.configure({
    props: [
        highlighting  // highlight.js からインポートした highlighting を使用
    ]
});

// 言語サポートの定義
const mizarLanguage = LRLanguage.define({
    parser: parserWithMetadata
});

function mizar() {
    return new LanguageSupport(mizarLanguage);
}

function bracketHighlighter() {
    return ViewPlugin.fromClass(class {
      constructor(view) {
        this.decorations = this.buildDecorations(view);
      }

      update(update) {
        if (update.docChanged || update.viewportChanged) {
          this.decorations = this.buildDecorations(update.view);
        }
      }

      buildDecorations(view) {
        const builder = new RangeSetBuilder();
        const stack = [];
        const colors = ['bracket-color-0', 'bracket-color-1', 'bracket-color-2', 'bracket-color-3', 'bracket-color-4'];

        syntaxTree(view.state).iterate({
          enter: ({ type, from, to }) => {
            if (type.is('OpenParen') || type.is('OpenBrace') || type.is('OpenBracket')) {
              stack.push({ type, from, to });
              const level = stack.length % colors.length;
              builder.add(from, to, Decoration.mark({ class: colors[level] }));
            } else if (type.is('CloseParen') || type.is('CloseBrace') || type.is('CloseBracket')) {
              const open = stack.pop();
              if (open) {
                const level = (stack.length + 1) % colors.length;
                builder.add(from, to, Decoration.mark({ class: colors[level] }));
              }
            }
          }
        });

        return builder.finish();
      }
    }, {
      decorations: v => v.decorations
    });
  }

const editableCompartment = new Compartment();  // 共有Compartment
// グローバルオブジェクトとしてエディタを管理
const editors = window.editors = window.editors || {};
const lineNumberConfigs = window.lineNumberConfigs = window.lineNumberConfigs || {};
const editorOrder = window.editorOrder = window.editorOrder || [];

// エラーデコレーションのエフェクトと状態フィールドを定義
const errorDecorationEffect = StateEffect.define();
const errorDecorationsField = StateField.define({
    create: () => Decoration.none,
    update(decorations, tr) {
        decorations = decorations.map(tr.changes);
        for (let effect of tr.effects) {
            if (effect.is(errorDecorationEffect)) {
                decorations = decorations.update({ add: effect.value });
            }
        }
        return decorations;
    },
    provide: f => EditorView.decorations.from(f)
});

// DOMContentLoaded時の初期化
document.addEventListener("DOMContentLoaded", function () {
    window.mizarInitialized = true;

    const wrappers = document.querySelectorAll('.mizarWrapper');
    if (wrappers.length === 0) {
        console.warn("No Mizar blocks found.");
        return;
    }

    wrappers.forEach((block) => {
        const mizarId = block.id.replace('mizarBlock', '');
        if (!block.querySelector('.editor-container').firstChild) {
            setupMizarBlock(block, mizarId);
        }
    });
}, { once: true }); // イベントリスナーを一度だけ実行

// パネルの表示を制御するエフェクトとステートフィールド
const toggleErrorPanel = StateEffect.define();
const errorPanelState = StateField.define({
    create: () => null,
    update(value, tr) {
        for (let e of tr.effects) {
            if (e.is(toggleErrorPanel)) value = e.value;
        }
        return value;
    },
    provide: f => showPanel.from(f, val => val ? createPanel(val) : null)
});

function createPanel(content) {
    return (_view) => {
        let dom = document.createElement("div");
        dom.innerHTML = content;
        dom.className = "cm-error-panel";
        return { dom };
    };
}

function calculateStartLineNumber(targetMizarId) {
    let totalLines = 1; // 最初の行を1に変更
    for (let mizarId of editorOrder) {
        if (mizarId === targetMizarId) {
            break;
        }
        totalLines += editors[mizarId].state.doc.lines;
    }
    return totalLines; // ここで+1を追加しない
}

function adjustLineNumbersForAllEditors() {
    let totalLines = 1; // 最初の行を1に変更
    for (let mizarId of editorOrder) {
        const editor = editors[mizarId];
        const startLine = totalLines;
        const lineNumberExtension = lineNumbers({
            formatNumber: number => `${number + startLine - 1}`
        });
        // Compartmentのreconfigureメソッドを使用して設定を更新
        editor.dispatch({
            effects: lineNumberConfigs[mizarId].reconfigure(lineNumberExtension)
        });
        // 次のエディタの開始行番号のために行数を加算
        totalLines += editor.state.doc.lines;
    }
}

function getEditorForGlobalLine(globalLine) {
    let currentLine = 1;
    for (let mizarId of editorOrder) {
        const editor = editors[mizarId];
        const editorLines = editor.state.doc.lines;
        if (globalLine >= currentLine && globalLine < currentLine + editorLines) {
            return { editor, localLine: globalLine - currentLine + 1 };
        }
        currentLine += editorLines;
    }
    return null;
}

// エディタの初期設定時にリスナーを追加
function setupMizarBlock(mizarBlock, mizarId) {
    const editorContainer = mizarBlock.querySelector('.editor-container');
    editorContainer.id = `editorContainer${mizarId}`;
    const editorContent = editorContainer.getAttribute('data-content');

    // テンポラリDOM要素を作成してinnerHTMLに設定することでデコードを実行
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = editorContent;
    const decodedContent = tempDiv.textContent || tempDiv.innerText || "";

    const lineNumberConfig = new Compartment();
    lineNumberConfigs[mizarId] = lineNumberConfig;  // 各エディタの設定を保持

    // EditorViewの初期化部分
    const editor = new EditorView({
        state: EditorState.create({
            doc: decodedContent, // デコードされたコンテンツを使用
            extensions: [
                lineNumberConfig.of(lineNumbers({ formatNumber: number => `${number + calculateStartLineNumber(mizarId) - 1}` })),
                // EditorCustomThemeは削除
                editableCompartment.of(EditorView.editable.of(false)),  // 初期状態を読み取り専用に設定
                syntaxHighlighting(highlightStyle),  // 追加: ハイライトスタイルの適用
                bracketHighlighter(),
                EditorView.updateListener.of(update => {
                    if (update.docChanged) {
                        adjustLineNumbersForAllEditors();  // すべてのエディタの行番号を更新
                    }
                }),
                errorPanelState,
                errorDecorationsField,
                syntaxHighlighting(highlightStyle),
                mizar() // Mizarの言語サポートを追加
            ]
        }),
        parent: editorContainer
    });
    window.editors[mizarId] = editor; // エディタをオブジェクトに追加
    editorOrder.push(mizarId); // エディタのIDを順序付けて保持

    const editButton = mizarBlock.querySelector('button[id^="editButton"]');
    const mizfButton = mizarBlock.querySelector('button[id^="mizfButton"]');
    const resetButton = mizarBlock.querySelector('button[id^="resetButton"]');

    editButton.addEventListener('click', () => {
        editor.dispatch({
            effects: editableCompartment.reconfigure(EditorView.editable.of(true)) // 編集可能に設定
        });
        editButton.style.display = 'none';
        mizfButton.style.display = 'inline';  // 検証ボタンを表示
        resetButton.style.display = 'inline'; // resetボタンも表示
    });

    mizfButton.addEventListener('click', () => {
        if (mizarBlock.isRequestInProgress) {
            return; // すでにこのブロックでリクエストが進行中
        }
        mizarBlock.isRequestInProgress = true; // リクエスト開始をマーク
        startMizarCompilation(mizarBlock, toggleErrorPanel, mizarId);
    });

    resetButton.addEventListener('click', async () => {
        // エディタを最初の状態に戻す（Wiki本体に保存されている文書を再読み込み）
        const initialContent = editorContainer.getAttribute('data-content'); // 初期コンテンツを再取得
        const tempDiv = document.createElement('div'); // HTMLエンティティのデコード用
        tempDiv.innerHTML = initialContent;
        const decodedContent = tempDiv.textContent || tempDiv.innerText || "";

        editor.dispatch({
            changes: { from: 0, to: editor.state.doc.length, insert: decodedContent } // 初期コンテンツを挿入
        });

        editor.dispatch({
            effects: editableCompartment.reconfigure(EditorView.editable.of(false)) // 読み取り専用に戻す
        });

        editButton.style.display = 'inline';  // 編集ボタンを再表示
        mizfButton.style.display = 'none';    // 検証ボタンを非表示
        resetButton.style.display = 'none';     // クリアボタンを非表示

        // エラーパネルを非表示にする
        editor.dispatch({
            effects: toggleErrorPanel.of(null)  // エラーパネルを非表示に設定
        });

        // エラーデコレーションも削除する
        editor.dispatch({
            effects: errorDecorationEffect.of([])  // エラーデコレーションをリセット
        });

        try {
            const response = await fetch(DOKU_BASE + "lib/exe/ajax.php?call=clear_temp_files", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                }
            });

            // JSONで返ってくることを期待する
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                // JSONのパースに失敗した場合はHTMLレスポンスだと判断
                throw new Error("Server returned a non-JSON response: " + text);
            }

            if (data.success) {
                console.log(data.message);
            } else {
                console.error("Failed to clear temporary files:", data.message);
            }
        } catch (error) {
            console.error("Error clearing temporary files:", error);
        }
    });

    // Enterキーで行を増やすリスナーを追加
    editor.dom.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && editor.state.facet(EditorView.editable)) {
            e.preventDefault();
            const transaction = editor.state.update({
                changes: { from: editor.state.selection.main.head, insert: '\n' }
            });
            editor.dispatch(transaction);
        }
    });
}

function scrollToLine(editor, line) {
    const lineInfo = editor.state.doc.line(line);
    editor.dispatch({
        scrollIntoView: { from: lineInfo.from, to: lineInfo.to }
    });
}

async function startMizarCompilation(mizarBlock, toggleErrorPanel, mizarId) {
    // 既存のイベントソースがあれば閉じる
    if (mizarBlock.eventSource) {
        mizarBlock.eventSource.close();
    }

    const combinedContent = getCombinedContentUntil(mizarBlock);
    const data = "content=" + encodeURIComponent(combinedContent);

    try {
        const response = await fetch(DOKU_BASE + "lib/exe/ajax.php?call=view_compile", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: data
        });

        if (!response.ok) {
            throw new Error("Network response was not ok");
        }

        mizarBlock.eventSource = new EventSource(DOKU_BASE + "lib/exe/ajax.php?call=view_sse&" + data);

        mizarBlock.eventSource.onmessage = function(event) {
            const editor = editors[mizarId];
            let content = editor.state.field(errorPanelState) || '';
            content += event.data + '<br>';
            editor.dispatch({
                effects: toggleErrorPanel.of(content)
            });

            // エラーメッセージが含まれているかどうかをチェックしてパネルの背景色を設定
            if (content.includes('❌')) {
                editor.dom.querySelector('.cm-error-panel').style.backgroundColor = '#fcc';
            } else {
                editor.dom.querySelector('.cm-error-panel').style.backgroundColor = '#ccffcc';
            }
        };

        // コンパイルが完了したことを示すイベントを受け取る
        mizarBlock.eventSource.addEventListener('compileFinished', () => {
            finalizeCompilation(mizarBlock);
        });

        // エラー情報がある場合に受信するイベントリスナー
        mizarBlock.eventSource.addEventListener('compileErrors', function(event) {
            try {
                const errors = JSON.parse(event.data);
                let errorContent = editors[mizarId].state.field(errorPanelState) || '';
                const decorationsPerEditor = {}; // 各エディタのデコレーションを保持

                errors.forEach(function(error) {
                    const { line, column, message } = error;
                    const link = `<a href="#" class="error-link" data-line="${line}" data-column="${column}">[Ln ${line}, Col ${column}]</a>`;
                    errorContent += `❌ ${message} ${link}<br>`;

                    // エラー位置に下線を引くデコレーションを追加
                    const editorInfo = getEditorForGlobalLine(line);
                    if (editorInfo) {
                        const { editor, localLine } = editorInfo;
                        const lineInfo = editor.state.doc.line(localLine);
                        const from = lineInfo.from + (column - 1);
                        const to = from + 1;

                        if (from >= lineInfo.from && to <= lineInfo.to) { // 範囲が有効であることを確認
                            const deco = Decoration.mark({
                                class: "error-underline",
                                attributes: { title: `${message} (Ln ${line}, Col ${column})` }  // ツールチップの追加
                            }).range(from, to);
                            const editorId = Object.keys(editors).find(key => editors[key] === editor);
                            if (!decorationsPerEditor[editorId]) {
                                decorationsPerEditor[editorId] = [];
                            }
                            decorationsPerEditor[editorId].push(deco);
                        }
                    }
                });

                // 各エディタにデコレーションを適用
                for (let editorId in decorationsPerEditor) {
                    const editor = editors[editorId];
                    const decorations = decorationsPerEditor[editorId];
                    if (decorations.length > 0) {
                        editor.dispatch({
                            effects: errorDecorationEffect.of(decorations.sort((a, b) => a.from - b.from))
                        });
                    }
                }

                // エラーとコンパイル結果を指定ブロックにのみ適用
                editors[mizarId].dispatch({
                    effects: [
                        toggleErrorPanel.of(errorContent)
                    ]
                });

                // エラーメッセージが含まれているかどうかをチェックしてパネルの背景色を設定
                if (errorContent.includes('❌')) {
                    editors[mizarId].dom.querySelector('.cm-error-panel').style.backgroundColor = '#fcc';
                } else {
                    editors[mizarId].dom.querySelector('.cm-error-panel').style.backgroundColor = '#ccffcc';
                }

                // エラーメッセージ内のリンクにクリックイベントを追加
                const errorPanel = editors[mizarId].dom.querySelector('.cm-error-panel');
                errorPanel.querySelectorAll('.error-link').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const line = parseInt(link.getAttribute('data-line'), 10);
                        const editorInfo = getEditorForGlobalLine(line);
                        if (editorInfo) {
                            const { editor, localLine } = editorInfo;
                            scrollToLine(editor, localLine);
                        }
                    });
                });
            } catch (e) {
                console.error('Failed to parse error data:', e);
                console.error('Event data:', event.data);
            }
        });

        mizarBlock.eventSource.onerror = () => {
            mizarBlock.eventSource.close();
            mizarBlock.isRequestInProgress = false;
            finalizeCompilation(mizarBlock); // コンパイル処理が完了した後の処理
        };
    } catch (error) {
        console.error("Fetch error: ", error);
        mizarBlock.isRequestInProgress = false;
    }
}

function getCombinedContentUntil(mizarBlock) {
    let combinedContent = '';
    const mizarBlocks = document.querySelectorAll('.mizarWrapper');
    const blockIndex = Array.from(mizarBlocks).indexOf(mizarBlock);

    // 指定したブロックまでのコンテンツを結合
    for (let i = 0; i <= blockIndex; i++) {
        const mizarId = editorOrder[i];
        const editor = editors[mizarId];
        if (editor && editor.state) {
            const blockContent = editor.state.doc.toString();
            combinedContent += blockContent + "\n"; // 正しい順序でコンテンツを追加
        }
    }

    return combinedContent.trim();
}

function finalizeCompilation(mizarBlock) {
    // mizarBlock から直接ボタン要素を取得
    const mizfButton = mizarBlock.querySelector('[id^="mizfButton"]');
    const resetButton = mizarBlock.querySelector('[id^="resetButton"]');

    // mizfButtonを非表示にし、resetButtonを表示
    mizfButton.style.display = 'none';
    resetButton.style.display = 'inline-block';

    // このブロックのリクエスト進行中状態をリセット
    mizarBlock.isRequestInProgress = false;
}

window.createMizarFile = async function(filename) {
    const combinedContent = collectMizarContents();

    if (!combinedContent) {
        console.error('Error: Combined content is empty.');
        return;
    }

    try {
        const response = await fetch(DOKU_BASE + 'lib/exe/ajax.php?call=create_combined_file', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'content=' + encodeURIComponent(combinedContent) + '&filename=' + encodeURIComponent(filename)
        });

        if (!response.ok) {
            console.error('Failed to create file: Network response was not ok');
            return;
        }

        const data = await response.json();
        if (data.success) {
            console.log('File created successfully:', filename);

            // ファイルの内容を表示する新しいタブを開く
            const contentWindow = window.open('', '_blank');
            contentWindow.document.write(`<pre>${data.data.content}</pre>`);
            contentWindow.document.title = filename;

            // Blobを使ってダウンロードリンクを作成
            const blob = new Blob([data.data.content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);

            // ダウンロードリンクの要素を作成して表示
            const downloadLink = contentWindow.document.createElement('a');
            downloadLink.href = url;
            downloadLink.download = filename;
            downloadLink.textContent = '⬇️ Click here to download the file';
            downloadLink.style.display = 'block';
            downloadLink.style.marginTop = '10px';
            // タブ内にダウンロードリンクを追加
            contentWindow.document.body.appendChild(downloadLink);

            // リソースの解放
            contentWindow.addEventListener('unload', () => {
                URL.revokeObjectURL(url);
            });
        } else {
            console.error('Failed to create file:', data.message);
        }
    } catch (error) {
        console.error('Error creating file:', error);
    }
};

function collectMizarContents() {
    let combinedContent = '';
    for (let mizarId of editorOrder) {
        const editor = editors[mizarId];
        if (editor && editor.state) {
            combinedContent += editor.state.doc.toString() + '\n';
        }
    }
    return combinedContent;
}

document.addEventListener("DOMContentLoaded", () => {
    const copyButtons = document.querySelectorAll('.copy-button');

    copyButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            const buttonElement = event.currentTarget;
            const mizarIdRaw = buttonElement.dataset.mizarid; // mizarBlock12 形式を取得
            const mizarId = mizarIdRaw.replace('mizarBlock', ''); // 数字部分を抽出

            // エディタが存在するか確認
            if (!editors[mizarId]) {
                console.error('エディタが見つかりません: ', mizarIdRaw);
                return;
            }

            const editor = editors[mizarId];
            const content = editor.state.doc.toString();

            // クリップボードにコピー
            navigator.clipboard.writeText(content).then(() => {
                buttonElement.textContent = 'Copied!';
                buttonElement.disabled = true;

                setTimeout(() => {
                    buttonElement.textContent = 'Copy';
                    buttonElement.disabled = false;
                }, 2000);
            }).catch((err) => {
                console.error('コピーに失敗しました: ', err);
            });
        });
    });
});