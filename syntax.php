<?php
/**
 * DokuWiki Plugin Mizar Verifiable Docs (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Yamada, M. <yamadam@mizar.work>
 */
class syntax_plugin_mizarverifiabledocs extends \dokuwiki\Extension\SyntaxPlugin {
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
        $this->Lexer->addSpecialPattern('<mizar\s+[^>]+>.*?</mizar>', $mode, 'plugin_mizarverifiabledocs');
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
            $renderer->doc .= '<div class="mizarWrapper" id="' . $mizarId . '">';
            $renderer->doc .= '<dl class="file">';
            $renderer->doc .= '<button class="copy-button" data-mizarid="' . $mizarId . '">Copy</button>';
            $renderer->doc .= '<button id="resetButton' . $mizarId . '" class="reset-button">Reset</button>';
            $renderer->doc .= '<button id="editButton' . $mizarId . '" class="edit-button">Edit</button>';
            $renderer->doc .= '<button id="compileButton' . $mizarId . '" class="compile-button">Compile</button>';
            $renderer->doc .= '<button id="hideButton' . $mizarId . '" class="hide-button">Hide</button>';
            $renderer->doc .= '<button id="showButton' . $mizarId . '" class="show-button">Show</button>';

            $renderer->doc .= '<dt><a href="#" onclick="createMizarFile(\'' . $filename . '\'); return false;" title="クリックしてコンテンツをダウンロード" class="file-download">' . $filename . '</a></dt>';
            $renderer->doc .= '<dd><div class="editor-container" data-content="' . htmlspecialchars($content) . '"></div></dd>';
            $renderer->doc .= '</dl>';
            $renderer->doc .= '<div id="output' . $mizarId . '" class="output"></div>';
            $renderer->doc .= '<script type="module" src="' . DOKU_BASE . 'lib/plugins/mizarverifiabledocs/dist/script.js"></script>';
            $renderer->doc .= '</div>';
        } else {
            $renderer->doc .= "<mizar $filename>$content</mizar>";
        }
        return true;
    }
}