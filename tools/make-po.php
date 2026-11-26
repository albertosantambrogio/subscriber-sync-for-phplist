<?php
// php makepo.php strings.json it.php languages-dir
list(, $json, $tr, $dir) = $argv;
$strings = json_decode(file_get_contents($json), true);
// Plugin header strings (scanned by WordPress for the plugins screen).
$strings[] = array('msgid' => 'Subscribes new WordPress users to a phpList list through a signed endpoint, with optional notification digests.', 'plural' => null, 'ctx' => null, 'refs' => array('subscriber-sync-for-phplist.php'), 'comment' => 'Description of the plugin');
$it = include $tr;
$esc = function ($s) { return '"' . addcslashes($s, "\\\"\n\t") . '"'; };
$head = function ($lang) {
	$h = "Project-Id-Version: Subscriber Sync for phpList 0.1.0\nReport-Msgid-Bugs-To: https://github.com/albertosantambrogio/subscriber-sync-for-phplist/issues\nPOT-Creation-Date: " . gmdate('Y-m-d H:i+0000') . "\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nX-Domain: subscriber-sync-for-phplist\n";
	if ($lang) { $h .= "PO-Revision-Date: " . gmdate('Y-m-d H:i+0000') . "\nLanguage: $lang\nPlural-Forms: nplurals=2; plural=(n != 1);\n"; }
	return $h;
};
$build = function ($lang) use ($strings, $it, $esc, $head) {
	$o = "msgid \"\"\nmsgstr \"\"\n";
	foreach (explode("\n", rtrim($head($lang))) as $l) { $o .= $esc($l . "\n") . "\n"; }
	$missing = array();
	foreach ($strings as $s) {
		$o .= "\n";
		if ($s['comment']) $o .= '#. ' . $s['comment'] . "\n";
		$o .= '#: ' . implode(' ', $s['refs']) . "\n";
		if (strpos($s['msgid'], '%') !== false) $o .= "#, php-format\n";
		$o .= 'msgid ' . $esc($s['msgid']) . "\n";
		$t = $lang ? ($it[$s['msgid']] ?? null) : null;
		if ($lang && $t === null) $missing[] = $s['msgid'];
		if ($s['plural'] !== null) {
			$o .= 'msgid_plural ' . $esc($s['plural']) . "\n";
			$o .= 'msgstr[0] ' . $esc(is_array($t) ? $t[0] : '') . "\n" . 'msgstr[1] ' . $esc(is_array($t) ? $t[1] : '') . "\n";
		} else {
			$o .= 'msgstr ' . $esc(is_string($t) ? $t : '') . "\n";
		}
	}
	if ($missing) { fwrite(STDERR, "MANCANO:\n" . implode("\n", $missing) . "\n"); }
	return $o;
};
file_put_contents("$dir/subscriber-sync-for-phplist.pot", $build(null));
file_put_contents("$dir/subscriber-sync-for-phplist-it_IT.po", $build('it_IT'));
echo "ok\n";
