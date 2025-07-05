/**
 * DokuWiki Plugin Mizar Verifiable Docs (View Screen Script)
 *
 */

// 必要なモジュールをインポート
import { EditorState, Compartment, StateEffect, StateField, RangeSetBuilder } from "@codemirror/state";
import { EditorView, lineNumbers, showPanel, Decoration, ViewPlugin, keymap } from "@codemirror/view";
import { defaultKeymap, insertNewlineAndIndent } from "@codemirror/commands";
import {
  LRLanguage,
  LanguageSupport,
  syntaxHighlighting,
  HighlightStyle,
  syntaxTree,
  foldNodeProp,
  foldInside,
  foldGutter,
  foldKeymap,
  codeFolding,
  indentNodeProp,
} from "@codemirror/language"; // ★ codeFoldingなど
import { parser } from "./mizar-parser.js";
import { tags as t } from "@lezer/highlight";
import { highlighting } from "./highlight.js"; // highlight.js からインポート

// ショートカット定義
const myKeymap = [
  ...defaultKeymap, // 基本的なキー操作（例: Ctrl+Z など）を利用
  ...foldKeymap,
  { key: "Enter", run: insertNewlineAndIndent }, // Enterで改行+インデント
];

// -----------------------------
// スタイルの定義
// -----------------------------
const highlightStyle = HighlightStyle.define([
  { tag: t.controlKeyword, class: "control-keyword" },
  { tag: t.function(t.keyword), class: "function-keyword" },
  { tag: t.keyword, class: "general-keyword" },
  { tag: t.typeName, class: "type-name" },
  { tag: t.meta, class: "meta-info" },
  { tag: t.lineComment, class: "line-comment" },
  { tag: t.paren, class: "paren" },
  { tag: t.brace, class: "brace" },
  { tag: t.squareBracket, class: "square-bracket" },
]);

// -----------------------------
// パーサー設定: 折りたたみ可能なノードを指定
// -----------------------------
let parserWithMetadata = parser.configure({
  props: [
    highlighting,
    foldNodeProp.add({
      DefinitionBlock: foldInside,
      Proof: foldInside,
      NowBlock: foldInside,
      HerebyBlock: foldInside,
    }),
  ],
});

// -----------------------------
// 言語サポートの定義
// -----------------------------
const mizarLanguage = LRLanguage.define({
  parser: parserWithMetadata,
});

// -----------------------------
// インデントの定義
// -----------------------------
const myIndent = indentNodeProp.add({
  // "Proof" ノードが来たら親のインデント +1 段
  Proof(context) {
    return context.lineIndent(context.node.from) + context.unit;
  },
  DefinitionBlock(context) {
    return context.lineIndent(context.node.from) + context.unit;
  },
  NowBlock(context) {
    return context.lineIndent(context.node.from) + context.unit;
  },
  HerebyBlock(context) {
    return context.lineIndent(context.node.from) + context.unit;
  }
});

// インデント込みの拡張 (mizarWithIndent)
const mizarWithIndent = mizarLanguage.configure({
  props: [myIndent],
});

// ★必要であれば、mizarWithIndent を直接使うか、
// 下記のように mizar() 関数内で呼び出して使ってもOK

// mizar() は インデント拡張なしのバージョン
export function mizar() {
  return new LanguageSupport(mizarLanguage);
}

// -----------------------------
// ブラケット強調のプラグイン (色分けなど)
// -----------------------------
function bracketHighlighter() {
  return ViewPlugin.fromClass(
    class {
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
        const colors = [
          "bracket-color-0",
          "bracket-color-1",
          "bracket-color-2",
          "bracket-color-3",
          "bracket-color-4",
        ];

        syntaxTree(view.state).iterate({
          enter: ({ type, from, to }) => {
            if (
              type.is("OpenParen") ||
              type.is("OpenBrace") ||
              type.is("OpenBracket")
            ) {
              stack.push({ type, from, to });
              const level = stack.length % colors.length;
              builder.add(from, to, Decoration.mark({ class: colors[level] }));
            } else if (
              type.is("CloseParen") ||
              type.is("CloseBrace") ||
              type.is("CloseBracket")
            ) {
              const open = stack.pop();
              if (open) {
                const level = (stack.length + 1) % colors.length;
                builder.add(from, to, Decoration.mark({ class: colors[level] }));
              }
            }
          },
        });
        return builder.finish();
      }
    },
    {
      decorations: (v) => v.decorations,
    }
  );
}

// -----------------------------
// Compartment, editors,などのグローバル管理
// -----------------------------
const editableCompartment = new Compartment(); // 共有
const editors = (window.editors = window.editors || {});
const lineNumberConfigs = (window.lineNumberConfigs = window.lineNumberConfigs || {});
const editorOrder = (window.editorOrder = window.editorOrder || []);

// エラーデコレーション用
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
  provide: (f) => EditorView.decorations.from(f),
});

// -----------------------------
// Hide/Show 関連
// -----------------------------
function toggleMizarEditor(wrapper, hide) {
  const elementsToToggle = wrapper.querySelectorAll(
    ".editor-container, .copy-button, .edit-button, .reset-button, .compile-button"
  );
  elementsToToggle.forEach((el) => {
    el.style.display = hide ? "none" : "";
  });

  const hideButton = wrapper.querySelector(".hide-button");
  const showButton = wrapper.querySelector(".show-button");
  if (hideButton && showButton) {
    hideButton.style.display = hide ? "none" : "inline";
    showButton.style.display = hide ? "inline" : "none";
  }
}

// -----------------------------
// DOMContentLoaded時の初期化
// -----------------------------
document.addEventListener(
  "DOMContentLoaded",
  function () {
    window.mizarInitialized = true;

    const wrappers = document.querySelectorAll(".mizarWrapper");
    if (wrappers.length === 0) {
      console.warn("No Mizar blocks found.");
      return;
    }

    wrappers.forEach((block) => {
      const mizarId = block.id.replace("mizarBlock", "");
      if (!block.querySelector(".editor-container").firstChild) {
        setupMizarBlock(block, mizarId);
      }
    });

    // Hide/Show ボタン
    const hideButtons = document.querySelectorAll(".hide-button");
    hideButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const parentWrapper = button.closest(".mizarWrapper");
        if (!parentWrapper) return;
        toggleMizarEditor(parentWrapper, true);
      });
    });

    const showButtons = document.querySelectorAll(".show-button");
    showButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const parentWrapper = button.closest(".mizarWrapper");
        if (!parentWrapper) return;
        toggleMizarEditor(parentWrapper, false);
      });
    });

    // Hide All, Show All
    const hideAllButton = document.getElementById("hideAllButton");
    const showAllButton = document.getElementById("showAllButton");
    const resetAllButton = document.getElementById("resetAllButton");

    if (hideAllButton && showAllButton) {
      hideAllButton.addEventListener("click", (e) => {
        toggleAllWrappers(true);
        hideAllButton.style.display = "none";
        showAllButton.style.display = "";
        e.target.blur();
      });

      showAllButton.addEventListener("click", (e) => {
        toggleAllWrappers(false);
        showAllButton.style.display = "none";
        hideAllButton.style.display = "";
        e.target.blur();
      });
    }

    function toggleAllWrappers(hide) {
      const allWrappers = document.querySelectorAll(".mizarWrapper");
      allWrappers.forEach((wrapper) => {
        toggleMizarEditor(wrapper, hide);
      });
    }

    // Reset All ボタン
    if (resetAllButton) {
      resetAllButton.addEventListener("click", (e) => {
        const allWrappers = document.querySelectorAll(".mizarWrapper");
        allWrappers.forEach((wrapper) => {
          const resetBtn = wrapper.querySelector("button[id^='resetButton']");
          if (resetBtn) {
            resetBtn.click();
          }
        });
        e.target.blur();
      });
    }
  },
  { once: true }
);

// -----------------------------
// エラー表示パネル
// -----------------------------
const toggleErrorPanel = StateEffect.define();
const errorPanelState = StateField.define({
  create: () => null,
  update(value, tr) {
    for (let e of tr.effects) {
      if (e.is(toggleErrorPanel)) value = e.value;
    }
    return value;
  },
  provide: (f) => showPanel.from(f, (val) => (val ? createPanel(val) : null)),
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
  let totalLines = 1;
  for (let mizarId of editorOrder) {
    if (mizarId === targetMizarId) {
      break;
    }
    totalLines += editors[mizarId].state.doc.lines;
  }
  return totalLines;
}

function adjustLineNumbersForAllEditors() {
  let totalLines = 1;
  for (let mizarId of editorOrder) {
    const editor = editors[mizarId];
    const startLine = totalLines;
    const lineNumberExtension = lineNumbers({
      formatNumber: (number) => `${number + startLine - 1}`,
    });
    editor.dispatch({
      effects: lineNumberConfigs[mizarId].reconfigure(lineNumberExtension),
    });
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

// -----------------------------
// エディタの初期設定
// -----------------------------
function setupMizarBlock(mizarBlock, mizarId) {
  const editorContainer = mizarBlock.querySelector(".editor-container");
  editorContainer.id = `editorContainer${mizarId}`;
  const editorContent = editorContainer.getAttribute("data-content");

  const tempDiv = document.createElement("div");
  tempDiv.innerHTML = editorContent;
  const decodedContent = tempDiv.textContent || tempDiv.innerText || "";

  const lineNumberConfig = new Compartment();
  lineNumberConfigs[mizarId] = lineNumberConfig;

  // codeFolding()を追加して折りたたみを有効化
  // ※ここで mizarWithIndent を使いたい場合は差し替えます
  const editor = new EditorView({
    state: EditorState.create({
      doc: decodedContent,
      extensions: [
        lineNumberConfig.of(
          lineNumbers({
            formatNumber: (number) =>
              `${number + calculateStartLineNumber(mizarId) - 1}`,
          })
        ),
        editableCompartment.of(EditorView.editable.of(false)),
        syntaxHighlighting(highlightStyle),
        codeFolding(), // ★ 折りたたみ
        foldGutter(),
        keymap.of(myKeymap), // ← キーマップを登録
        bracketHighlighter(),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            adjustLineNumbersForAllEditors();
          }
        }),
        errorPanelState,
        errorDecorationsField,
        mizarWithIndent,
      ],
    }),
    parent: editorContainer,
  });

  window.editors[mizarId] = editor;
  editorOrder.push(mizarId);

  // ボタン系
  const editButton = mizarBlock.querySelector("button[id^='editButton']");
  const compileButton = mizarBlock.querySelector("button[id^='compileButton']");
  const resetButton = mizarBlock.querySelector("button[id^='resetButton']");
  const graphButton = mizarBlock.querySelector("button[id^='graphButton']");

  editButton.addEventListener("click", () => {
    editor.dispatch({
      effects: editableCompartment.reconfigure(EditorView.editable.of(true)),
    });
    editButton.style.display = "none";
    compileButton.style.display = "inline";
    resetButton.style.display = "inline";
  });

  compileButton.addEventListener("click", () => {
      if (mizarBlock.isRequestInProgress) {
          return;
      }
      mizarBlock.isRequestInProgress = true;

      // Spinnerを表示 (この処理を追加)
      const spinner = document.createElement('div');
      spinner.className = 'loading-spinner';
      spinner.innerHTML = 'Loading...';
      compileButton.parentNode.insertBefore(spinner, compileButton.nextSibling);

      startMizarCompilation(mizarBlock, toggleErrorPanel, mizarId).finally(() => {
          // Spinnerを削除 (レスポンス後に削除)
          spinner.remove();
          mizarBlock.isRequestInProgress = false;
      });
  });

  resetButton.addEventListener("click", async () => {
    const initialContent = editorContainer.getAttribute("data-content");
    const tempDiv = document.createElement("div");
    tempDiv.innerHTML = initialContent;
    const decodedContent = tempDiv.textContent || tempDiv.innerText || "";

    editor.dispatch({
      changes: { from: 0, to: editor.state.doc.length, insert: decodedContent },
    });

    editor.dispatch({
      effects: editableCompartment.reconfigure(EditorView.editable.of(false)),
    });

    editButton.style.display = "inline";
    compileButton.style.display = "none";
    resetButton.style.display = "none";

    editor.dispatch({
      effects: toggleErrorPanel.of(null),
    });
    editor.dispatch({
      effects: errorDecorationEffect.of([]),
    });

    try {
      const response = await fetch(
        DOKU_BASE + "lib/exe/ajax.php?call=clear_temp_files",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
        }
      );

      const text = await response.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (error) {
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

  graphButton.addEventListener("click", () => {
    if (mizarBlock.isRequestInProgress) return;
    mizarBlock.isRequestInProgress = true;

    // スピナー表示（任意）
    const spinner = document.createElement("div");
    spinner.className = "loading-spinner";
    spinner.textContent = "Visualizing...";
    graphButton.parentNode.insertBefore(spinner, graphButton.nextSibling);

    startGraphVisualization(mizarBlock, mizarId)
      .finally(() => {
        spinner.remove();
        mizarBlock.isRequestInProgress = false;
      });
  });

}

// -----------------------------
// 行スクロール関連
// -----------------------------
function scrollToLine(editor, line) {
  const lineInfo = editor.state.doc.line(line);
  editor.dispatch({
    scrollIntoView: { from: lineInfo.from, to: lineInfo.to },
  });
}

// -----------------------------
// コンパイル開始
// -----------------------------
async function startMizarCompilation(mizarBlock, toggleErrorPanel, mizarId) {
  if (mizarBlock.eventSource) {
    mizarBlock.eventSource.close();
  }

  const combinedContent = getCombinedContentUntil(mizarBlock);
  const data = "content=" + encodeURIComponent(combinedContent);

  try {
    const response = await fetch(DOKU_BASE + "lib/exe/ajax.php?call=view_compile", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: data,
    });
    if (!response.ok) {
      throw new Error("Network response was not ok");
    }

    mizarBlock.eventSource = new EventSource(
      DOKU_BASE + "lib/exe/ajax.php?call=view_sse&" + data
    );

    mizarBlock.eventSource.onmessage = function (event) {
      const editor = editors[mizarId];
      let content = editor.state.field(errorPanelState) || "";
      content += event.data + "<br>";
      editor.dispatch({
        effects: toggleErrorPanel.of(content),
      });

      if (content.includes("❌")) {
        editor.dom.querySelector(".cm-error-panel").style.backgroundColor = "#fcc";
      } else {
        editor.dom.querySelector(".cm-error-panel").style.backgroundColor =
          "#ccffcc";
      }
    };

    mizarBlock.eventSource.addEventListener("compileErrors", function (event) {
      try {
        const errors = JSON.parse(event.data);
        let errorContent = editors[mizarId].state.field(errorPanelState) || "";
        const decorationsPerEditor = {};

        errors.forEach(function (error) {
          const { line, column, message } = error;
          const link = `<a href="#" class="error-link" data-line="${line}" data-column="${column}">[Ln ${line}, Col ${column}]</a>`;
          errorContent += `❌ ${message} ${link}<br>`;

          const editorInfo = getEditorForGlobalLine(line);
          if (editorInfo) {
            const { editor, localLine } = editorInfo;
            const lineInfo = editor.state.doc.line(localLine);
            const from = lineInfo.from + (column - 1);
            const to = from + 1;

            if (from >= lineInfo.from && to <= lineInfo.to) {
              const deco = Decoration.mark({
                class: "error-underline",
                attributes: {
                  title: `${message} (Ln ${line}, Col ${column})`,
                },
              }).range(from, to);
              const editorId = Object.keys(editors).find(
                (key) => editors[key] === editor
              );
              if (!decorationsPerEditor[editorId]) {
                decorationsPerEditor[editorId] = [];
              }
              decorationsPerEditor[editorId].push(deco);
            }
          }
        });

        for (let editorId in decorationsPerEditor) {
          const editor = editors[editorId];
          const decorations = decorationsPerEditor[editorId];
          if (decorations.length > 0) {
            editor.dispatch({
              effects: errorDecorationEffect.of(decorations.sort((a, b) => a.from - b.from)),
            });
          }
        }

        editors[mizarId].dispatch({
          effects: [toggleErrorPanel.of(errorContent)],
        });

        if (errorContent.includes("❌")) {
          editors[mizarId].dom.querySelector(".cm-error-panel").style.backgroundColor =
            "#fcc";
        } else {
          editors[mizarId].dom.querySelector(".cm-error-panel").style.backgroundColor =
            "#ccffcc";
        }

        const errorPanel = editors[mizarId].dom.querySelector(".cm-error-panel");
        errorPanel.querySelectorAll(".error-link").forEach((link) => {
          link.addEventListener("click", function (e) {
            e.preventDefault();
            const line = parseInt(link.getAttribute("data-line"), 10);
            const editorInfo = getEditorForGlobalLine(line);
            if (editorInfo) {
              const { editor, localLine } = editorInfo;
              scrollToLine(editor, localLine);
            }
          });
        });
      } catch (e) {
        console.error("Failed to parse error data:", e);
        console.error("Event data:", event.data);
      }
    });

    mizarBlock.eventSource.onerror = () => {
      mizarBlock.eventSource.close();
      mizarBlock.isRequestInProgress = false;
      finalizeCompilation(mizarBlock);
    };
  } catch (error) {
    console.error("Fetch error: ", error);
    mizarBlock.isRequestInProgress = false;
  }
}
//-----------------------------
// ★ Graph 可視化処理
//-----------------------------
async function startGraphVisualization(mizarBlock, mizarId) {
  const combined = getCombinedContentUntil(mizarBlock);   // 既存関数を再利用
  const data = "content=" + encodeURIComponent(combined);

  try {
    const res = await fetch(
      DOKU_BASE + "lib/exe/ajax.php?call=view_graph",
      {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: data,
      }
    );
    if (!res.ok) throw new Error("network error");

    // ── JSON を 1 変数で受けて衝突を回避 ──
    const json = await res.json();
    const out  = mizarBlock.querySelector(`#outputmizarBlock${mizarId}`);

    if (json.success) {
      /* ▼ SVG と X ボタンをまとめて挿入 */
      out.innerHTML =
        `<div class="graph-wrapper">
          <button class="close-graph" title="Close">✖</button>
          ${json.data.svg}
        </div>`;
      out.style.display = 'block';

      /* ▼ ボタンにイベントを付与 */
      out.querySelector('.close-graph').addEventListener('click', () => {
        out.innerHTML = '';
        out.style.display = 'none';
      });
    } else {
      out.innerHTML = `<pre class="err">${json.data.err}</pre>`;
      out.style.display = 'block';
    }

  } catch (e) {
    console.error(e);
    alert("Graph generation failed: " + e.message);
  }
}

function getCombinedContentUntil(mizarBlock) {
  let combinedContent = "";
  const mizarBlocks = document.querySelectorAll(".mizarWrapper");
  const blockIndex = Array.from(mizarBlocks).indexOf(mizarBlock);

  for (let i = 0; i <= blockIndex; i++) {
    const mizarId = editorOrder[i];
    const editor = editors[mizarId];
    if (editor && editor.state) {
      const blockContent = editor.state.doc.toString();
      combinedContent += blockContent + "\n";
    }
  }
  return combinedContent.trim();
}

function finalizeCompilation(mizarBlock) {
  const compileButton = mizarBlock.querySelector("[id^='compileButton']");
  const resetButton = mizarBlock.querySelector("[id^='resetButton']");

  compileButton.style.display = "none";
  resetButton.style.display = "inline-block";
  mizarBlock.isRequestInProgress = false;
}

// -----------------------------
// ファイル作成関連
// -----------------------------
window.createMizarFile = async function (filename) {
  const combinedContent = collectMizarContents();
  if (!combinedContent) {
    console.error("Error: Combined content is empty.");
    return;
  }
  if (!filename.endsWith(".miz")) {
    filename += ".miz";
  }

  try {
    const response = await fetch(DOKU_BASE + "lib/exe/ajax.php?call=create_combined_file", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body:
        "content=" +
        encodeURIComponent(combinedContent) +
        "&filename=" +
        encodeURIComponent(filename),
    });

    if (!response.ok) {
      console.error("Failed to create file: Network response was not ok");
      return;
    }

    const data = await response.json();
    if (data.success) {
      console.log("File created successfully:", filename);

      const blob = new Blob([data.data.content], { type: "application/octet-stream" });
      const url = URL.createObjectURL(blob);

      const contentWindow = window.open("", "_blank");
      contentWindow.document.write(
        "<pre>" +
          data.data.content
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;") +
          "</pre>"
      );
      contentWindow.document.title = filename;

      const downloadLink = contentWindow.document.createElement("a");
      downloadLink.href = url;
      downloadLink.download = filename;
      downloadLink.textContent = "⬇️ Click here to download the file";
      downloadLink.style.display = "block";
      downloadLink.style.marginTop = "10px";
      contentWindow.document.body.appendChild(downloadLink);

      contentWindow.addEventListener("unload", () => {
        URL.revokeObjectURL(url);
      });
    } else {
      console.error("Failed to create file:", data.message);
    }
  } catch (error) {
    console.error("Error creating file:", error);
  }
};

function collectMizarContents() {
  let combinedContent = "";
  for (let mizarId of editorOrder) {
    const editor = editors[mizarId];
    if (editor && editor.state) {
      combinedContent += editor.state.doc.toString() + "\n";
    }
  }
  return combinedContent;
}

// -----------------------------
// Copyボタンの実装
// -----------------------------
document.addEventListener("DOMContentLoaded", () => {
  const copyButtons = document.querySelectorAll(".copy-button");
  copyButtons.forEach((button) => {
    button.addEventListener("click", (event) => {
      const buttonElement = event.currentTarget;
      const mizarIdRaw = buttonElement.dataset.mizarid;
      const mizarId = mizarIdRaw.replace("mizarBlock", "");

      if (!editors[mizarId]) {
        console.error("エディタが見つかりません: ", mizarIdRaw);
        return;
      }

      const editor = editors[mizarId];
      const content = editor.state.doc.toString();

      navigator.clipboard
        .writeText(content)
        .then(() => {
          buttonElement.textContent = "Copied!";
          buttonElement.disabled = true;

          setTimeout(() => {
            buttonElement.textContent = "Copy";
            buttonElement.disabled = false;
          }, 2000);
        })
        .catch((err) => {
          console.error("コピーに失敗しました: ", err);
        });
    });
  });
});
