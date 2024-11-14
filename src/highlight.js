import { styleTags, tags as t } from "@lezer/highlight";

export const highlighting = styleTags({
    // トークン名と標準タグの対応付け
  KeywordControl: t.controlKeyword,              // 制御キーワード
  SupportFunction: t.function(t.keyword),        // 関数キーワード
  KeywordOther: t.keyword,                        // 一般的なキーワード
  EntityNameType: t.typeName,                    // 型名やエンティティ名
  ReasoningPhrase: t.meta,                        // メタ情報（推論句）
  Comment: t.lineComment,                         // 行コメント
  OpenParen: t.paren,                             // 開き括弧
  CloseParen: t.paren,                            // 閉じ括弧
  OpenBrace: t.brace,                             // 開き中括弧
  CloseBrace: t.brace,                            // 閉じ中括弧
  OpenBracket: t.squareBracket,                   // 開き角括弧
  CloseBracket: t.squareBracket,                  // 閉じ角括弧
});