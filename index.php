<?php
// Only report errors, do not report warnings
error_reporting(E_ERROR);

// Disable script timeout - timeouts can be handled by the AJAX calling thread instead
ini_set('max_execution_time', 0);

// // Include library for detecting browser
// require_once('url_suffixes.php');

$SERVER_URL = getServerUrl();

ob_start();
$queryString = $_SERVER['QUERY_STRING'];
if (strlen($queryString) > 0) {
  sendResponseForHttpRequest($queryString, 'GET');
} else {
  // Redirect to home page
  header("Location: index.html");
}
ob_flush();

// Send an HTTP request (see https://stackoverflow.com/questions/5647461) and respond according to the response 
function sendResponseForHttpRequest($url, $method, $content = null, $header = null) {
  $url = preprocessAbsoluteUrl($url);
  $options = array('http' => array('method' => $method));
  if ($content !== null) {
    $options['http']['content'] = $content;
  }
  if ($header !== null) {
    $options['http']['header'] = $header;
  }
  $context = stream_context_create($options);
  $response = file_get_contents($url, false, $context);
  if ($response === false) {
    throw new Exception("Problem reading data from $url: $php_errormsg");
  }

  sendHeaders($http_response_header);

  //print("<pre>".print_r($http_response_header, true)."</pre>");
  $contentType = getContentType($http_response_header);
  if ((strpos($contentType, 'text/html') !== false)) {
    $charset = getCharset($contentType);
    if ($charset === '') {
      $dom = new DOMDocument();
    } else {
      $dom = new DOMDocument("1.0", $charset);
    }
    $dom->loadHTML($response);
    convertLinks($dom, $url);
    echo ($dom->saveHTML());
  } else {
    echo ($response);
  }
}

function sendHeaders($httpResponseHeaderArray) {
  $skipping = false;
  foreach ($httpResponseHeaderArray as $header) {
    if (strStartsWith($header, 'HTTP/1.1 3')) {
      $skipping = true;
    } else if (strStartsWith($header, 'HTTP/')) {
      $skipping = false;
    }
    if (!$skipping) {
      header($header);
    }
  }
}

function getContentType($httpResponseHeaderArray) {
  $httpResponseHeaderStr = implode("\n", $httpResponseHeaderArray);
  if (preg_match_all('/^content-type\s*:\s*(.*)$/mi', $httpResponseHeaderStr, $matches, PREG_SET_ORDER)) {
    return end($matches)[1];
  } else {
    return NULL;
  }
}

function getCharset($contentType) {
  return preg_match('/;\s*charset\s*=\s*(.*)$/i', $contentType, $matches) ? $matches[1] : '';
}

function convertLinks(DOMNode $domNode, $pageUrl) {
  foreach ($domNode->childNodes as $node) {
    if ($node instanceof DOMElement) {
      switch ($node->tagName) {
        case 'audio':
        case 'embed':
        case 'iframe':
        case 'img':
        case 'input':
        case 'script':
        case 'source':
        case 'track':
        case 'video':
          $src = trim($node->getAttribute('src'));
          if ($src !== '') {
            $node->setAttribute('src', getConvertedLinkUrl($src, $pageUrl));
          }
          $datasrc = trim($node->getAttribute('data-src'));
          if ($datasrc !== '') {
            $node->setAttribute('data-src', getConvertedLinkUrl($datasrc, $pageUrl));
          }
          break;
        case 'a':
        case 'area':
        case 'base':
        case 'link':
          $href = trim($node->getAttribute('href'));
          if ($href !== '' && !strStartsWith($href, 'javascript:')) {
            $node->setAttribute('href', getConvertedLinkUrl($href, $pageUrl));
          }
          break;
      }
    }

    if ($node->hasChildNodes()) {
      convertLinks($node, $pageUrl);
    }
  }
}

function getConvertedLinkUrl($linkUrl, $pageUrl) {
  if (strStartsWith($linkUrl, '#')) return $linkUrl;
  if (strContains($linkUrl, ':')) {
    if (!preg_match($linkUrl, '/^https?:/i')) return $linkUrl;
  } else {
    if (strStartsWith($linkUrl, '/')) {
      $pageUrlPart = getUrlUpToDomain($pageUrl);
    } else {
      $pageUrlPart = getUrlUpToPath($pageUrl);
    }
    $linkUrl = "{$pageUrlPart}{$linkUrl}";
  }
  global $SERVER_URL;
  return "{$SERVER_URL}?{$linkUrl}";
}

function getServerUrl() {
  $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
  return "{$scheme}://{$_SERVER['HTTP_HOST']}{$_SERVER['URL']}";
}

function preprocessAbsoluteUrl($url) {
  $scheme = getUrlScheme($url);
  if ($scheme === '') {
    $urlNoLeadingSlashes = rtrim(ltrim($url, '/'));
    return "http://{$urlNoLeadingSlashes}";
  } else {
    return trim($url);
  }
}

function getUrlUpToDomain($url) {
  return getUrlUpToDomainOrPath($url, false);
}

function getUrlUpToPath($url) {
  return getUrlUpToDomainOrPath($url, true);
}

function getUrlUpToDomainOrPath($url, $includePath) {
  $parts = parse_url($url);
  $scheme = isset($parts['scheme']) ? "{$parts['scheme']}://" : '';
  $user = isset($parts['user']) ? $parts['user'] : '';
  $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
  $userpass = $user !== '' || $pass !== "{$user}{$pass}@" ? '' : '';
  $host = isset($parts['host']) ? $parts['host'] : '';
  $port = isset($parts['port']) ? ":{$parts['port']}" : '';
  $path = $includePath && isset($parts['path']) ? rtrim($parts['path'], '/') : '';
  return "{$scheme}{$userpass}{$host}{$port}{$path}";
}

function getUrlScheme($url) {
  return parse_url($url, PHP_URL_SCHEME) ?: '';
}

function getUrlHost($url) {
  return parse_url($url, PHP_URL_HOST) ?: '';
}

function strStartsWith(string $haystack, string $needle) {
  return substr($haystack, 0, strlen($needle)) === $needle;
}

function strContains(string $haystack, string $needle) {
  return strpos($haystack, $needle) !== false;
}