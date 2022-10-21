<?php
// Only report errors, do not report warnings
error_reporting(E_ERROR);

// Disable script timeout - timeouts can be handled by the AJAX calling thread instead
ini_set('max_execution_time', 0);

// // Include library for detecting browser
// require_once('url_suffixes.php');

$SERVER_URL = getServerUrl();

$queryString = $_SERVER['QUERY_STRING'];
if (strlen($queryString) > 0) {
  sendResponseForHttpRequest($queryString, 'GET');
} else {
  // Redirect to home page
  header("Location: index.html");
}

// Send an HTTP request (see https://stackoverflow.com/questions/5647461) and respond according to the response 
function sendResponseForHttpRequest($url) {
  $url = preprocessAbsoluteUrl($url);
  $curlResponse = sendHttpRequest($url);

  $headers = $curlResponse['headers'];
  $content = $curlResponse['content'];
  sendHeaders($headers);

  $contentType = getContentType($headers);
  if ((strpos($contentType, 'text/html') !== false)) {
    $charset = getCharset($contentType);
    if ($charset === '') {
      $dom = new DOMDocument();
    } else {
      $dom = new DOMDocument("1.0", $charset);
    }
    $dom->loadHTML($content);
    convertLinksInDom($dom, $url);
    $html = $dom->saveHTML();
    echo ($html);
  } else {
    echo ($content);
  }
}

function sendHttpRequest($url) {
  global $SERVER_URL;

  //TODO: Random user agent
  // $useragent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.89 Safari/537.36';
  $curDir = __DIR__;
  $urlFromHostToDomain = getUrlFromHostToDomain($url);
  $cookieFilenameBase = urlencode("{$urlFromHostToDomain}-{$_SERVER['REMOTE_ADDR']}-{$_SERVER['HTTP_X_FORWARDED_FOR']}-{$_SERVER['HTTP_USER_AGENT']}");
  $cookieFilename = "{$curDir}/cookies/{$cookieFilenameBase}.txt";

  $ch = curl_init($url);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $headers['Referer'] = preprocessAbsoluteUrl(str_replace("{$SERVER_URL}?", '', $headers['Referer']));
    $headers['Host'] = $headers['Referer'];
    $headers['Origin'] = $headers['Referer'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    $payload = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  }
  curl_setopt($ch, CURLOPT_FAILONERROR, true);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFilename);
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFilename);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_ENCODING, "");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_AUTOREFERER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
  // curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
  // curl_setopt($ch, CURLOPT_REFERER, 'http://www.google.com/');
  curl_setopt($ch, CURLOPT_VERBOSE, true);
  curl_setopt($ch, CURLOPT_STDERR, fopen("{$curDir}/curl.log", 'w+'));

  //These next lines are for the magic "good cert confirmation"
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

  //for local domains:
  //you need to get the pem cert file for the root ca or intermediate CA that you signed all the domain certificates with so that PHP curl can use it...sorry batteries not included
  //place the pem or crt ca certificate file in the same directory as the php file for this code to work
  curl_setopt($ch, CURLOPT_CAINFO, "{$curDir}/cacert.pem");
  curl_setopt($ch, CURLOPT_CAPATH, "{$curDir}/cacert.pem");

  $headers = [];

  // this function is called by curl for each header received
  curl_setopt(
    $ch,
    CURLOPT_HEADERFUNCTION,
    function ($curl, $header) use (&$headers) {
      array_push($headers, $header);
      return strlen($header);
    }
  );

  $content = curl_exec($ch);
  if (curl_errno($ch)) {
    echo ("Failed to read from $url");
    $curlError = curl_error($ch);
    echo ("<br/><br/>{$curlError}");
    curl_close($ch);
    exit();
  } else {
    curl_close($ch);
    return array('content' => $content, 'headers' => $headers);
  }
}

function sendHeaders($headers) {
  $skipping = false;
  foreach ($headers as $header) {
    $lowerCaseHeader = strtolower($header);
    if (strStartsWith($lowerCaseHeader, 'http/1.1 3')) {
      $skipping = true;
    } else if (strStartsWith($lowerCaseHeader, 'http/')) {
      $skipping = false;
    }
    if (
      !$skipping
      && !strStartsWith($lowerCaseHeader, 'content-encoding')
      && !strStartsWith($lowerCaseHeader, 'transfer-encoding')
    ) {
      $header = convertLinksInString($header);
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

function convertLinksInDom(DOMNode $domNode, $pageUrl) {
  foreach ($domNode->childNodes as $node) {
    if ($node instanceof DOMElement) {
      switch ($node->tagName) {
        case 'a':
        case 'area':
        case 'base':
        case 'link':
          convertAttribute($node, 'href', $pageUrl);
          break;
        case 'audio':
        case 'embed':
        case 'iframe':
        case 'img':
        case 'input':
        case 'script':
        case 'source':
        case 'track':
        case 'video':
          convertAttribute($node, 'src', $pageUrl);
          convertAttribute($node, 'data-src', $pageUrl);
          break;
        case 'form':
          convertAttribute($node, 'action', $pageUrl);
      }
    }

    if ($node->hasChildNodes()) {
      convertLinksInDom($node, $pageUrl);
    }
  }
}

function convertAttribute($node, $attr, $pageUrl) {
  $src = trim($node->getAttribute($attr));
  $node->setAttribute($attr, getConvertedLinkUrl($src, $pageUrl));
}

function convertLinksInString(string $str) {
  global $SERVER_URL;
  $urlRegex = <<<END
https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,4}\b(?:[-a-zA-Z0-9@:%_\+.~#?&\/=]*)
END;
  return preg_replace("/$urlRegex/i", "{$SERVER_URL}?\$0", $str);
}

function getConvertedLinkUrl($linkUrl, $pageUrl) {
  global $SERVER_URL;
  if ($linkUrl === '' || strStartsWith($linkUrl, '#')) return $linkUrl;
  if (strContains($linkUrl, ':')) {
    if (!preg_match('/^https?:\\/\\//i', $linkUrl)) return $linkUrl;
  } else {
    if (strStartsWith($linkUrl, '/')) {
      $pageUrlPart = getUrlUpToDomain($pageUrl);
    } else {
      $pageUrlPart = getUrlUpToPath($pageUrl);
    }
    $linkUrl = "{$pageUrlPart}{$linkUrl}";
  }
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

function getScheme($url) {
  return parse_url($url, PHP_URL_SCHEME);
}

function getUrlUpToDomain($url) {
  return getUrlUpToDomainOrPath($url, false);
}

function getUrlFromHostToDomain($url) {
  return getUrlUpToDomainOrPath($url, false, true);
}

function getUrlUpToPath($url) {
  return getUrlUpToDomainOrPath($url, true);
}

function getUrlUpToDomainOrPath($url, $includePath, $fromHost = false) {
  $parts = parse_url($url);
  $scheme = isset($parts['scheme']) && !$fromHost ? "{$parts['scheme']}://" : '';
  $user = isset($parts['user']) ? $parts['user'] : '';
  $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
  $userpass = $user !== '' || $pass !== '' && !$fromHost ? "{$user}{$pass}@" : '';
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
