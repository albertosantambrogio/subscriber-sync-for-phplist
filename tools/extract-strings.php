<?php
// Minimal gettext extractor for the plugin (text domain subscriber-sync-for-phplist). Outputs JSON.
$dir = $argv[1]; $domain = 'subscriber-sync-for-phplist';
$fns = array('__'=>1,'_e'=>1,'esc_html__'=>1,'esc_html_e'=>1,'esc_attr__'=>1,'esc_attr_e'=>1,'_n'=>2,'_x'=>1);
$out = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $f) {
  if ($f->getExtension() !== 'php' || strpos($f->getPathname(), '/docs/') !== false) continue;
  $rel = substr($f->getPathname(), strlen($dir) + 1);
  $t = token_get_all(file_get_contents($f->getPathname()));
  $lastComment = null; $lastCommentLine = -10;
  for ($i = 0; $i < count($t); $i++) {
    if (is_array($t[$i]) && in_array($t[$i][0], array(T_COMMENT, T_DOC_COMMENT)) && stripos($t[$i][1], 'translators:') !== false) {
      $lastComment = trim(preg_replace('#^/\*+|\*+/$|^//#', '', trim($t[$i][1]))); $lastCommentLine = $t[$i][2];
    }
    if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || !isset($fns[$t[$i][1]])) continue;
    $fn = $t[$i][1]; $line = $t[$i][2];
    $j = $i + 1; while (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
    if ($t[$j] !== '(') continue;
    // collect top-level args as constant strings
    $args = array(); $cur = ''; $depth = 0; $ok = true;
    for ($k = $j + 1; $k < count($t); $k++) {
      $tok = $t[$k];
      if ($tok === '(' || $tok === '[') { $depth++; $ok = false; continue; }
      if ($tok === ')' || $tok === ']') { if ($depth === 0) { $args[] = $ok ? $cur : null; break; } $depth--; continue; }
      if ($tok === ',' && $depth === 0) { $args[] = $ok ? $cur : null; $cur = ''; $ok = true; continue; }
      if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) { $cur .= eval('return ' . $tok[1] . ';'); continue; }
      if (is_array($tok) && in_array($tok[0], array(T_WHITESPACE, T_COMMENT))) continue;
      if ($tok === '.') continue;
      $ok = false;
    }
    $dom = $fn === '_n' ? ($args[3] ?? null) : ($fn === '_x' ? ($args[2] ?? null) : ($args[1] ?? null));
    if ($dom !== $domain || $args[0] === null) { fwrite(STDERR, "skip $rel:$line $fn\n"); continue; }
    $key = ($fn === '_x' ? $args[1] . "\4" : '') . $args[0];
    if (!isset($out[$key])) $out[$key] = array('msgid' => $args[0], 'plural' => $fn === '_n' ? $args[1] : null, 'ctx' => $fn === '_x' ? $args[1] : null, 'refs' => array(), 'comment' => null);
    $out[$key]['refs'][] = "$rel:$line";
    if ($lastComment && $line - $lastCommentLine <= 2) $out[$key]['comment'] = $lastComment;
  }
}
echo json_encode(array_values($out), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
