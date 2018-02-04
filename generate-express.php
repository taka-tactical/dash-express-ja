<?php

//----------------------------------------
// Config
//----------------------------------------

// set your language (en/fr/de/es/it/ja/ru/zh-cn/zh-tw/ko/pt-br)
$cfg_lang = 'ja';
// set API version (4x/3x)
$cfg_ver  = '4x';

// set true, if you have font trouble with google open sans (e.g. Zeal on windows)
$cfg_nosans = true;


//----------------------------------------
//
// Main process
//
//----------------------------------------

// get manual html
exec('rm -rf Express.docset/Contents/Resources/');
//exec('mkdir -p Express.docset/Contents/Resources/');
mkdir('Express.docset/Contents/Resources/', 0777, true);
exec("wget -rk http://expressjs.com/{$cfg_lang}/index.html --execute robots=off --include-directories=/{$cfg_lang},/css,/js,/fonts,/images");
exec('mv ' . __DIR__ . '/expressjs.com ' . __DIR__ . '/Express.docset/Contents/Resources/Documents/');
//exec('rm -rf ' . __DIR__ . '/expressjs.com/');

// gen Info.plist
file_put_contents(__DIR__ . '/Express.docset/Contents/Info.plist', <<<ENDE
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key>
	<string>express-{$cfg_lang}</string>
	<key>CFBundleName</key>
	<string>Express {$cfg_ver}-{$cfg_lang}</string>
	<key>DocSetPlatformFamily</key>
	<string>express</string>
	<key>isDashDocset</key>
	<true/>
	<key>dashIndexFilePath</key>
	<string>{$cfg_lang}/{$cfg_ver}/api.html</string>
</dict>
</plist>
ENDE
);
copy(__DIR__ . '/icon.png', __DIR__ . '/Express.docset/icon.png');

// gen docset
if (!$html = file_get_contents(__DIR__ . "/Express.docset/Contents/Resources/Documents/{$cfg_lang}/index.html")) {
	echo "\nExpress docset creation failure: index.html not opened\n";
	exit;
}

$dom  = new DomDocument;
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

$db = new sqlite3(__DIR__ . '/Express.docset/Contents/Resources/docSet.dsidx');
$db->query('CREATE TABLE searchIndex(id INTEGER PRIMARY KEY, name TEXT, type TEXT, path TEXT)');
$db->query('CREATE UNIQUE INDEX anchor ON searchIndex (name, type, path)');

// remove google open sans font
if ($cfg_nosans) {
	$html = remove_googlefonts($html);
}

// remove garbages
file_put_contents(
	__DIR__ . "/Express.docset/Contents/Resources/Documents/{$cfg_lang}/index.html",
	remove_githubfooter(remove_headerlinks(remove_noticebox($html)))
);


// add links from the table of contents
echo "\nCreate search indexes ...\n\n";
$edited = [];

foreach ($dom->getElementsByTagName('a') as $a) {
	// need 'li > a' nodes
	if (!$a->parentNode || $a->parentNode->tagName != 'li') {
		continue;
	}

	// check link
	$href = $a->getAttribute('href');
	$str  = substr($href, 0, 6);

	if ($str == 'https:' || !strncmp($str, 'http:', 5)) {
		continue;
	}

	$file = "{$cfg_lang}/" . preg_replace('/#.*$/', '', $href);

	if (!isset($edited[$file]) && $file != "{$cfg_lang}/index.html") {
		$html = file_get_contents(__DIR__ . "/Express.docset/Contents/Resources/Documents/{$file}");

		// remove google open sans font
		if ($cfg_nosans) {
			$html = remove_googlefonts($html);
		}

		// remove garbages
		$html = remove_githubfooter(remove_headerlinks(remove_noticebox($html)));

		file_put_contents(
			__DIR__ . "/Express.docset/Contents/Resources/Documents/{$file}",
			$html
		);
		$edited[$file] = true;
	}

	// no chapters in toc
	//$name = trim(preg_replace('#\s+#u', ' ', preg_replace('#^[A-Z0-9-]+\.#u', '', $a->nodeValue)));
	$name = trim(preg_replace('#\s+#u', ' ', $a->nodeValue));

	if (empty($name)) {
		continue;
	}
	$db->query("INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"{$name}\",\"Guide\",\"{$cfg_lang}/{$href}\")");

	echo "{$name}\n";
}


// adjust another 'api.html' file
if ($html = file_get_contents(__DIR__ . "/Express.docset/Contents/Resources/Documents/{$cfg_lang}/api.html")) {

	// remove google open sans font
	if ($cfg_nosans) {
		$html = remove_googlefonts($html);
	}

	// remove garbages
	file_put_contents(
		__DIR__ . "/Express.docset/Contents/Resources/Documents/{$cfg_lang}/api.html",
		remove_githubfooter(remove_headerlinks(remove_noticebox($html)))
	);
}


// add classes from the API reference
$file    = "{$cfg_lang}/{$cfg_ver}";
$results = [];

if (!$html = file_get_contents(__DIR__ . "/Express.docset/Contents/Resources/Documents/{$file}/api.html")) {
	echo "\nExpress docset creation failure: api.html not opened\n";
	exit;
}

echo "\n\n";
preg_match_all('|^<li id=".+"><a href="(.+)">(.+)</a>\r?\n?$|mu', $html, $results, PREG_SET_ORDER);

foreach ($results as $val) {
	$db->query("INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"{$val[2]}\",\"Class\",\"{$file}/{$val[1]}\")");
	echo "Added Class '{$val[2]}'\n";
}


// add properties/methods/events from the API reference
$results = [];
$nameadj = [
	'Methods'	=> 'Method',
	'Properties'=> 'Property',
	'Events'	=> 'Event',
];

preg_match_all('|^<ul id="\w+-menu">\r?\n?(.+)</ul>\r?\n?</li>\r?\n?$|msuU', $html, $results, PREG_SET_ORDER);

foreach ($results as $val) {
	$val = preg_replace('|^<li id=".+"><a|mu', '<a', str_replace(['<li>', '</li>'], '', $val[1]));
	$val = array_filter(explode('<em>', $val), function ($v) {
		return trim($v) ? true : false;
	});

	foreach ($val as $menu) {
		$menu  = explode('</em>', $menu);
		$class = str_replace(array_keys($nameadj), array_values($nameadj), trim($menu[0]));
		$items = [];

		preg_match_all('|^<a href="(.+)">(.+)</a>\r?\n?$|mu', trim($menu[1]), $items, PREG_SET_ORDER);

		foreach ($items as $item) {
			$db->query("INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"{$item[2]}\",\"{$class}\",\"{$file}/{$item[1]}\")");
			echo "Added {$class} '{$item[2]}'\n";
		}
	}
}

echo "\nExpress docset created !\n";


//----------------------------------------
// Helper functions
//----------------------------------------

// remove notice box
function remove_noticebox($html) {
	if ($html && ($p = strpos($html, '<div id="i18n-notice-box"')) !== false) {
		$tag ="</div>\n</div>\n";

		if (($q = strpos($html, $tag, $p + 1)) !== false) {
			$html = substr($html, 0, $p) . substr($html, $q + strlen($tag));
		}
	}

	return $html;
}

// remove & replace header links
function remove_headerlinks($html) {
	// replace
	$target = [
		'search'  => [
			'<a href="http://expressjs.com/" class="express">Express</a>',
		],
		'replace' => [
			'<span class="express">Express</span>',
		],
	];

	$html = str_replace($target['search'], $target['replace'], $html);

	// remove
	$target = [
		['<li><a href="index.html" id="home-menu" class="active"', "</li>\n"],
		['<li><a href="index.html" id="home-menu"', "</li>\n"],
		['<li><a href="../index.html" id="home-menu"', "</li>\n"],
		['<li><a href="http://expressjs.com/2x/"', "</li>\n"],
	];

	foreach ($target as $val) {
		if ($html && ($p = strpos($html, $val[0])) !== false) {
			if (($q = strpos($html, $val[1], $p + 1)) !== false) {
				$html = substr($html, 0, $p) . substr($html, $q + strlen($val[1]));
			}
		}
	}

	return $html;
}

// remove github footers
function remove_githubfooter($html) {
	$target = [
		['<div id="fork"', "</div>\n"],
		['<div id="github"', "</div>\n"],
	];

	foreach ($target as $val) {
		if ($html && ($p = strpos($html, $val[0])) !== false) {
			if (($q = strpos($html, $val[1], $p + 1)) !== false) {
				$html = substr($html, 0, $p) . substr($html, $q + strlen($val[1]));
			}
		}
	}

	return $html;
}

// remove google open sans font
function remove_googlefonts($html) {
	if ($html) {
		$html = preg_replace(
			'#^<link( rel="stylesheet")? href=("|\')http(s)?://fonts.googleapis.com/css\?family=Open\+Sans:.+>\r?\n?$#mu',
			'',
			$html
		);
	}

	return $html;
}


