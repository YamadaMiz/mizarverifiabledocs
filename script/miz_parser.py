#!/usr/bin/env python3
# --------------------------------------------------------------
#  miz_parser.py ――  Mizar 依存グラフ可視化
# --------------------------------------------------------------
#  theorem             : yellow   (Th…)
#  auto-theorem        : lavender (Th_auto…)
#  definition / auto   : teal     (Def… / Def_auto…)
#  lemma               : coral    (Lm…)
#  external article    : grey     (environ 節に列挙された語)
# --------------------------------------------------------------
from __future__ import annotations
import re
import sys
from pathlib import Path
from typing import Iterable

import networkx as nx
# from pyvis.network import Network


# ─── 色定義 ──────────────────────────────────────────
COL_TH   = "#ffc107"
COL_AUTO = "#b39ddb"
COL_DEF  = "#17a2b8"
COL_LM   = "#ff7f50"
COL_EXT  = "#9e9e9e"

# ─── 基本正規表現 ──────────────────────────────────
COMMENT_RE   = re.compile(r"::.*?$", re.M)
THEOREM_RE   = re.compile(r"\btheorem\b(?:\s+([A-Za-z0-9_]+)\s*:)?", re.I)
DEF_HDR_RE   = re.compile(r"\bdefinition\b(?:\s+([A-Za-z0-9_]+)\s*:)?", re.I)

# 行頭にある Def/Lm ラベル (  :Def1:  や  Def1:  )
INLINE_HEAD_RE = re.compile(r"^[ \t]*:?\s*((?:Def|Lm)\d+)\s*:", re.I | re.M)

# 行中に埋め込まれた :Def1: / :Lm3: など
INLINE_INLINE_RE = re.compile(r":\s*((?:Def|Lm)\d+)\s*:", re.I)

BY_RE   = re.compile(r"\bby\s+([^;]+);",   re.I | re.S)
FROM_RE = re.compile(r"\bfrom\s+([^;]+);", re.I | re.S)

# 〈記事:キーワード+番号〉も 1 トークン扱い
LABEL_RE = re.compile(
    r"[A-Z][A-Za-z0-9_]*:[A-Za-z0-9_]+\s*\d+"   #  TARSKI:def 1 など
    r"|[A-Z][A-Za-z0-9_]*"                      #  単体記事名・内部ラベル
)

FOLD_RE     = re.compile(r"^[A-Z](\d+)?$")          # A, A1 …（吸収対象）
INTERNAL_RE = re.compile(r"^(?:Th\d+|Th_auto\d+|Def\d+|Def_auto\d+|Lm\d+)$")
EXT_ART_RE  = re.compile(r"^[A-Z][A-Z0-9_]*$")


# ─── environ から外部アーティクル抽出 ────────────────
def externals(txt: str) -> set[str]:
    m = re.search(r"\benviron\b(.*?)(?:\bbegin\b)", txt, re.I | re.S)
    return set() if not m else set(re.findall(r"[A-Z][A-Z0-9_]*", m[1]))


# ─── 1 つの .miz を依存グラフに変換 ───────────────────
def parse_mizar(path: Path) -> nx.DiGraph:
    src = COMMENT_RE.sub("", path.read_text(encoding="utf-8", errors="ignore"))
    ext_art = externals(src)

    #―― theorem：明示番号を把握して auto 番衝突を防ぐ ―――――――――――
    explicit_nums, th_matches = set(), []
    for m in THEOREM_RE.finditer(src):
        th_matches.append(m)
        if m.group(1) and m.group(1).startswith("Th") and m.group(1)[2:].isdigit():
            explicit_nums.add(int(m.group(1)[2:]))

    entries, inline_taken = [], set()
    th_auto_i = def_auto_i = 1

    #── theorem ブロック登録 ──────────────────────────
    for m in th_matches:
        if m.group(1):                              # 明示ラベル
            lbl, kind = m.group(1), "th"
        else:                                       # auto 採番
            while th_auto_i in explicit_nums:
                th_auto_i += 1
            lbl, kind = f"Th_auto{th_auto_i}", "th_auto"
            th_auto_i += 1
        entries.append(dict(start=m.start(), end=m.end(), label=lbl, kind=kind))

    #── definition ヘッダ処理 ─────────────────────────
    for m in DEF_HDR_RE.finditer(src):
        hdr_s, hdr_e = m.span()
        # ブロック終端＝次 the/def 手前
        nxt_th  = THEOREM_RE.search(src, hdr_e)
        nxt_def = DEF_HDR_RE.search(src, hdr_e + 1)
        blk_end = min(nxt_th.start()  if nxt_th  else len(src),
                      nxt_def.start() if nxt_def else len(src))

        # --- 行頭ラベル ---
        for im in INLINE_HEAD_RE.finditer(src[hdr_e:blk_end]):
            abs_pos = hdr_e + im.start()
            lbl     = im.group(1)
            inline_taken.add(abs_pos)
            kind = "lemma" if lbl.lower().startswith("lm") else "def"
            entries.append(dict(start=abs_pos, end=abs_pos, label=lbl, kind=kind))

        # --- 行中ラベル ---
        for jm in INLINE_INLINE_RE.finditer(src[hdr_e:blk_end]):
            abs_pos = hdr_e + jm.start()
            if abs_pos in inline_taken:             # 行頭と重複しないように
                continue
            lbl  = jm.group(1)
            inline_taken.add(abs_pos)
            kind = "lemma" if lbl.lower().startswith("lm") else "def"
            entries.append(dict(start=abs_pos, end=abs_pos, label=lbl, kind=kind))

        # ヘッダに固有ラベルが無ければ auto 付与
        if not any(hdr_s <= e["start"] < hdr_e for e in entries):
            lbl = f"Def_auto{def_auto_i}"
            def_auto_i += 1
            entries.append(dict(start=hdr_s, end=hdr_e, label=lbl, kind="def"))

    #── ファイル行頭の Def/Lm (未登録) ─────────────────
    for m in INLINE_HEAD_RE.finditer(src):
        if m.start() in inline_taken:
            continue
        lbl  = m.group(1)
        kind = "lemma" if lbl.lower().startswith("lm") else "def"
        entries.append(dict(start=m.start(), end=m.end(), label=lbl, kind=kind))
        inline_taken.add(m.start())

    # 行中 :DefN: / :LmN: (未登録)
    for m in INLINE_INLINE_RE.finditer(src):
        if m.start() in inline_taken:
            continue
        lbl  = m.group(1)
        kind = "lemma" if lbl.lower().startswith("lm") else "def"
        entries.append(dict(start=m.start(), end=m.end(), label=lbl, kind=kind))

    #―― 時系列順 & ラベル集合 ―――――――――――――――――――――――
    entries.sort(key=lambda d: d["start"])
    internal_labels = {e["label"] for e in entries}

    #―― グラフ (ノード) ―――――――――――――――――――――――――――
    G = nx.DiGraph()
    color_of = {"th": COL_TH, "th_auto": COL_AUTO, "def": COL_DEF, "lemma": COL_LM}
    for e in entries:
        G.add_node(e["label"], color=color_of[e["kind"]], label=e["label"])

    #―― 参照エッジ解析 ―――――――――――――――――――――――――
    for idx, blk in enumerate(entries):
        parent = blk["label"]
        body = src[
            blk["end"]:
            entries[idx + 1]["start"] if idx + 1 < len(entries) else len(src)
        ]

        refs: set[str] = set()
        for seg in BY_RE.findall(body) + FROM_RE.findall(body):
            for tok in LABEL_RE.findall(seg):
                tok = tok.rstrip()
                if FOLD_RE.fullmatch(tok):
                    continue

                # ------------ 内部ラベル ------------
                if tok in internal_labels and INTERNAL_RE.fullmatch(tok):
                    refs.add(tok)
                    continue

                # ------------ 外部参照 ------------
                art = tok.split(":")[0]              # “XBOOLE_0:7” → “XBOOLE_0”
                if art in ext_art:
                    refs.add(art)                    # 番号無視で記事名だけ追加
                    continue

        refs.discard(parent)
        for r in refs:
            if r not in G:
                col = COL_EXT if r in ext_art else COL_TH
                G.add_node(r, color=col, label=r)
            G.add_edge(r, parent)

    return G


# ─── 可視化 (HTML) ─────────────────────────────────
def visualize(G: nx.DiGraph, outfile: Path) -> None:
    net = Network(height="800px", width="100%", directed=True, notebook=False)
    net.from_nx(G)
    net.barnes_hut(gravity=-8000, central_gravity=0.5)

    for n in net.nodes:
        n.update({
            "value": max(nx.degree(G, n["id"]), 1),
            "shape": "circle",
            "font": {"vadjust": 0},
        })

    net.show(str(outfile))
    print(f"★ 生成完了: {outfile}")


# ─── CLI ────────────────────────────────────────────
def main(files: Iterable[str]) -> None:
    paths = [Path(p) for p in files]
    if not paths:
        print("usage: python miz_parser.py <file1.miz> [file2.miz ...]")
        sys.exit(1)

    for p in paths:
        if not p.exists():
            print(f"※ file not found: {p}")
            continue
        graph = parse_mizar(p)
        # visualize(graph, p.with_stem(p.stem + "_graph").with_suffix(".html"))


if __name__ == "__main__":
    main(sys.argv[1:])
