<?php
/**
 * Default settings for the Mizar Verifiable Docs Plugin
 *
 * @author Yamada, M. <yamadam@mizar.work>
 */

 /* Mizar 本体ディレクトリ */
$conf['mizar_work_dir']  = '/var/www/html/mizarwork/';
$conf['mizar_share_dir'] = '/usr/local/share/mizar/';
$conf['mizar_exe_dir']   = '/usr/local/bin/';

/* Python 実行パス（空ならシステム PATH の python を使用） */
$conf['py_cmd'] = '';  // 例 "C:\\venvs\\mizar\\Scripts\\python.exe"