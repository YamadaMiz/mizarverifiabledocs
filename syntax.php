<?php
/**
 * DokuWiki Plugin Mizar proof checker (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Yamada, M. <yamadam@mizar.work>
 */
class syntax_plugin_mizarproofchecker extends \dokuwiki\Extension\SyntaxPlugin {
    /** @inheritDoc */
    public function getType() {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType() {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort() {
        return 195;
    }

    /** @inheritDoc */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<mizar\s+[^>]+>.*?</mizar>', $mode, 'plugin_mizarproofchecker');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        preg_match('/<mizar\s+([^>]+)>(.*?)<\/mizar>/s', $match, $matches);
        $filename = htmlspecialchars(trim($matches[1]));
        $content  = htmlspecialchars(trim($matches[2]));
        return array($state, $filename, $content);
    }

    public function render($mode, Doku_Renderer $renderer, $data) {
        static $mizarCounter = 0; // 一意のカウンターを追加
        list($state,$filename, $content) = $data;
        $mizarId = 'mizarBlock' . $mizarCounter++; // 一意のIDを生成

        if ($mode == 'xhtml') {
            // ボタンやエディタのHTMLを生成
            $renderer->doc .= '<div class="mizarWrapper" id="' . $mizarId . '">'; // ラッパーdivを追加
            $renderer->doc .= '<div id="copyMessage" style="display:none;">コンテンツがクリップボードにコピーされました。</div>';
            $renderer->doc .= '<dl class="file">';
            $renderer->doc .= '<button id="myEditorButton' . $mizarId . '">Editor</button>';
            $renderer->doc .= '<button id="verifyButton' . $mizarId . '" style="display:none;">mizf</button>';
            $renderer->doc .= '<button id="clearButton' . $mizarId . '" style="display:none;">clear</button>';
            $renderer->doc .= '<dt><a href="#" onclick="return copyToClipboard(\'' . $mizarId . '\');" title="クリックしてコンテンツをコピー" class="mediafile mf_miz clipboard-icon">' . $filename . '</a></dt>';
            // エディタ用のコンテナを準備
            $renderer->doc .= '<dd><div id="editorContainer' . $mizarId . '" class="editor-container" data-content="' . htmlspecialchars($content) . '"></div></dd>';
            $renderer->doc .= '</dl>';
            $renderer->doc .= '<div id="output' . $mizarId . '" style="padding: 10px; border: 1px solid #ccc; margin-top: 10px; white-space: pre-wrap; display: none;"></div>';
            $renderer->doc .= '<script type="text/javascript" src="' . DOKU_BASE . 'lib/plugins/mizarproofchecker/dist/script.js"></script>';
            $renderer->doc .= '</div>'; // ラッパーdivを閉じる
        } else {
            $renderer->doc .= "<mizar $filename>$content</mizar>";
        }
        return true;
    }
}