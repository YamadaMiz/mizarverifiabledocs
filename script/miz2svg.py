#!/usr/bin/env python3
import sys, subprocess, tempfile, os, networkx as nx
from pathlib import Path
from miz_parser import parse_mizar           # 解析だけ借用

def main():
    if len(sys.argv) != 2:
        sys.exit("usage: miz2svg.py <file.miz>")
    miz = Path(sys.argv[1]).resolve()
    G = parse_mizar(miz)
    dot = nx.nx_pydot.to_pydot(G).to_string()

    with tempfile.NamedTemporaryFile('w+', delete=False, suffix='.dot') as f:
        f.write(dot); dot_path = f.name
    try:
        svg = subprocess.check_output(['dot', '-Tsvg', dot_path])
        sys.stdout.buffer.write(svg)          # ← stdout に SVG
    finally:
        os.remove(dot_path)

if __name__ == '__main__': main()
