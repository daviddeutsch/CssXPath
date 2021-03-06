<?php
namespace Gt\CssXPath;

use DOMNode;

class Translator {
	protected $cssSelector;

	public function __construct(string $cssSelector, DOMNode $referenceNode = null) {
		$this->cssSelector = $cssSelector;
		$this->referenceNode = $referenceNode;
	}

	public function __toString():string {
		return $this->asXPath();
	}

	public function asXPath():string {
		// TODO: Implement.
		return "";
	}
}

const cssRegex =
	'/'
	. '(?P<star>\*)'
	. '|(:(?P<pseudo>[\w-]*))'
	. '|\(*(?P<pseudospecifier>["\']*[\w\h-]*["\']*)\)'
	. '|(?P<element>[\w-]*)'
	. '|(?P<child>\s*>\s*)'
	. '|(#(?P<id>[\w-]*))'
	. '|(\.(?P<class>\w*))'
	. '|(?P<sibling>\s*\+\s*)'
	. "(\[(?P<attribute>[\w-]*)((?P<avmod>.*=)*(?P<avvalue>[\\\"\'][^\"\']*[\\\"\'])\])*)"
	. '|(?P<descendant>\s+)'
	. '/';

function preg_match_collated($regex, $string, callable $transform=null): array
{
	preg_match_all($regex, $string, $matches, PREG_PATTERN_ORDER);

	$set = [];
	foreach ($matches[0] as $k => $v) {
		if (!empty($v)) $set[$k] = null;
	}

	foreach ($matches as $k => $m) {
		if (is_numeric($k)) continue;

		foreach ($m as $i => $match) {
			if ($match === '') continue;

			if ($transform) {
				$set[$i] = $transform($k, $match);
			} else {
				$set[$i] = ['type' => $k, 'content' => $match];
			}
		}
	}

	return $set;
}

function xpath($html, $path)
{
	$doc = new DOMDocument();
	@$doc->loadHTML($html);

	$xpath = new DOMXpath($doc);

	$elements = $xpath->query($path);

	if (!$elements) {
		return '';
	}

	if ($elements->length > 1) {
		$return = [];
		foreach ($elements as $element) {
			if ($element->childNodes->length > 1) {
				$return[] = utf8_decode(nodeToHtml($element)) . "\n";
			} else {
				$return[] = utf8_decode($element->nodeValue) . "\n";
			}
		}
	} else {
		$return = '';
		foreach ($elements as $element) {
			if ($element->childNodes->length > 1) {
				$return .= utf8_decode(nodeToHtml($element)) . "\n";
			} else {
				$return .= utf8_decode($element->nodeValue) . "\n";
			}
		}
	}

	return $return;
}

function csspath($html, $path)
{
	return xpath($html, CSStoXPath($path));
}

function CSStoXPath($css)
{
	$thread = preg_match_collated(cssRegex, $css);

	$thread = array_values($thread);

	$xpath = ['//'];
	$prevType = '';
	foreach ($thread as $k => $item) {
		$next = isset($thread[$k+1]) ? $thread[$k+1] : false;

		switch ($item['type']) {
			case 'star':
			case 'element':
				$xpath[] = $item['content'];
				break;
			case 'pseudo':
				$specifier = '';
				if ($next && $next['type'] == 'pseudospecifier') {
					$specifier = "{$next['content']}";
				}

				switch ($item['content']) {
					case 'disabled':
					case 'checked':
					case 'selected':
						$xpath[] = "[@{$item['content']}";
						break;
					case 'text':
						$xpath[] = '[@type="text"]';
						break;
					case 'contains':
						if (empty($specifier)) continue;

						$xpath[] = "[contains(text(),$specifier)]";
						break;
					case 'first-child':
						$prev = count($xpath) - 1;

						$xpath[$prev] = '*[1]/self::' . $xpath[$prev];
						break;
					case 'nth-child':
						if (empty($specifier)) continue;

						$prev = count($xpath) - 1;
						$previous = $xpath[$prev];

						if (substr($previous, -1, 1) === ']') {
							$xpath[$prev] = str_replace(']', " and position() = $specifier]", $xpath[$prev]);
						} else {
							$xpath[] = "[$specifier]";
						}
						break;
					case 'nth-of-type':
						if (empty($specifier)) continue;

						$prev = count($xpath) - 1;
						$previous = $xpath[$prev];

						if (substr($previous, -1, 1) === ']') {
							$xpath[] = "[$specifier]";
						} else {
							$xpath[] = "[$specifier]";
						}
						break;
				}
				break;
			case 'child':
				$xpath[] = '/';
				break;
			case 'id':
				$xpath[] = ($prevType != 'element'  ? '*' : '') . "[@id='{$item['content']}']";
				break;
			case 'class':
				$xpath[] = ($prevType != 'element'  ? '*' : '') . "[contains(concat(\" \",@class,\" \"),concat(\" \",\"{$item['content']}\",\" \"))]";
				break;
			case 'sibling':
				$xpath[] = "/following-sibling::*[1]/self::";
				break;
			case 'attribute':
				if (!$next || $next['type'] != 'avmod') {
					$xpath[] = "[@{$item['content']}]";

					continue;
				}

				$value = $thread[$k+2];

				switch ($next['content']) {
					case '=':
						$xpath[] = "[@{$item['content']}={$value['content']}]";
						break;
					case '~=':
						$xpath[] = "["
							. "contains("
							. "concat(\" \",@{$item['content']},\" \"),"
							. "concat(\" \",\"{$value['content']}\",\" \")"
							. ")"
							. "]";
						break;
					case '$=':
						$xpath[] = "["
							. "substring("
							. "@{$item['content']},"
							. "string-length(@{$item['content']})-" . strlen($item['content'])
							. ")=\"{$value['content']}\""
							. "]";
						break;
				}
				break;
			case 'descendant':
				$xpath[] = '//';
				break;
		}

		$prevType = $item['type'];
	}

	return implode("", $xpath);
}
