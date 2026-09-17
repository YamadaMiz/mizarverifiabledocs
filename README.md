Mizar Verifiable Docs Plugin for DokuWiki

Embed Mizar article blocks in wiki pages so users can edit and verify mathematical proofs directly.
Provides syntax highlighting, error reporting, proof checking, cross-references, and dependency
visualization for dynamically verifiable documentation of mathematics.

All documentation for this plugin can be found at:
https://www.dokuwiki.org/plugin:mizarverifiabledocs

Compatibility:
- Tested with DokuWiki 2026-07-14 "Mort"
- Windows 11 with XAMPP
- Ubuntu 22.04 with Apache and PHP 8.3

If you install this plugin manually, make sure it is installed in:
lib/plugins/mizarverifiabledocs/

The plugin directory must be named `mizarverifiabledocs`.

Please refer to https://www.dokuwiki.org/plugins for additional information
on how to install plugins in DokuWiki.

----
Copyright (C) 2024–2026 Yamada, M.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

See the COPYING file included with this plugin for details.

Third-party components bundled with this plugin:
- CodeMirror — MIT License (see licenses/codemirror/LICENSE)
- Lezer — MIT License (see licenses/lezer/LICENSE)

Requirements:
- Mizar
- Python 3.x
- networkx
- pyvis
- Graphviz (dot)
